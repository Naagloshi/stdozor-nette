<?php

declare(strict_types=1);

namespace App\Controls\CategoryList;

use App\Model\Enum\CategoryStatus;
use App\Model\Repository\AttachmentRepository;
use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ItemRepository;
use App\Model\Service\AttachmentService;
use App\Model\Service\CategoryAmountCalculator;
use Contributte\Translation\Translator;
use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

final class CategoryListControl extends Control
{
	private string $currency = '';

	// Category form modal state
	private bool $showCategoryForm = false;

	private ?int $editCategoryId = null;

	private ?int $categoryParentId = null;

	// Item form modal state
	private bool $showItemForm = false;

	private ?int $editItemId = null;

	private ?int $itemCategoryId = null;

	// Role-derived flags
	private bool $canEditAmount;

	private bool $canEditFlags;

	/**
	 * @param string[] $memberRoles
	 */
	public function __construct(
		private int $projectId,
		private bool $isOwner,
		private array $memberRoles,
		private CategoryRepository $categoryRepository,
		private CategoryAmountCalculator $amountCalculator,
		private ItemRepository $itemRepository,
		private AttachmentRepository $attachmentRepository,
		private AttachmentService $attachmentService,
		private Translator $translator,
	) {
		$this->canEditAmount = $isOwner;
		$this->canEditFlags = $isOwner || in_array('supervisor', $memberRoles, true);
	}

	public function render(?string $currency = null): void
	{
		if ($currency !== null) {
			$this->currency = $currency;
		}
		$categories = $this->categoryRepository->findByProject($this->projectId);
		$categoryTree = $this->categoryRepository->buildTree($categories);

		// Group root categories by status
		$statusOrder = ['in_progress', 'planned', 'completed'];
		$groupedCategories = array_fill_keys($statusOrder, []);
		foreach ($categoryTree as $node) {
			$s = $node['category']->status;
			if (isset($groupedCategories[$s])) {
				$groupedCategories[$s][] = $node;
			}
		}

		// Batch load items for all categories (single query)
		$categoryIds = array_map(fn($cat) => $cat->id, $categories);
		$itemsByCategory = $this->itemRepository->findByCategoryIds($categoryIds);

		// Batch load attachments for all items (single query)
		$allItemIds = [];
		foreach ($itemsByCategory as $items) {
			foreach ($items as $item) {
				$allItemIds[] = $item->id;
			}
		}
		$attachmentsByItem = $this->attachmentRepository->findByItemIds($allItemIds);

		$this->template->projectId = $this->projectId;
		$this->template->isOwner = $this->isOwner;
		$this->template->memberRoles = $this->memberRoles;
		$this->template->currency = $this->currency;
		$this->template->categoryCount = count($categories);
		$this->template->groupedCategories = $groupedCategories;
		$this->template->itemsByCategory = $itemsByCategory;
		$this->template->attachmentsByItem = $attachmentsByItem;

		// Form modal state
		$this->template->showCategoryForm = $this->showCategoryForm;
		$this->template->editCategoryId = $this->editCategoryId;
		$this->template->categoryParentId = $this->categoryParentId;
		$this->template->showItemForm = $this->showItemForm;
		$this->template->editItemId = $this->editItemId;
		$this->template->itemCategoryId = $this->itemCategoryId;
		$this->template->canEditAmount = $this->canEditAmount;
		$this->template->canEditFlags = $this->canEditFlags;

		$this->template->render(__DIR__ . '/CategoryListControl.latte');
	}

	public function handleDelete(int $id): void
	{
		$this->ensureOwner();
		$category = $this->loadCategory($id);
		$parentId = $category->parent_id;

		$this->categoryRepository->deleteRecursive($id);

		// Recalculate parent (propagates up to project) or project total for root categories
		if ($parentId) {
			$this->amountCalculator->recalculate($parentId);
		} else {
			$this->amountCalculator->recalculateProjectTotal($this->projectId);
		}

		$this->getPresenter()->flashMessage(
			$this->translator->translate('messages.category.flash.deleted'),
			'success',
		);
		$this->ajaxRedirect();
	}

	public function handleReorder(int $id, string $direction): void
	{
		$this->ensureOwner();
		$category = $this->loadCategory($id);

		if (!in_array($direction, ['up', 'down'], true)) {
			$this->getPresenter()->error('Invalid direction', 400);
		}

		$swapped = $this->categoryRepository->swapOrder($category, $direction);

		$this->getPresenter()->flashMessage(
			$this->translator->translate(
				$swapped ? 'messages.category.flash.reordered' : 'messages.category.flash.reorder_limit',
			),
			$swapped ? 'success' : 'info',
		);
		$this->ajaxRedirect();
	}

	public function handleChangeStatus(int $id, string $status): void
	{
		$this->ensureOwner();
		$category = $this->loadCategory($id);

		$currentStatus = CategoryStatus::tryFrom($category->status);
		$newStatus = CategoryStatus::tryFrom($status);

		if (!$currentStatus || !$newStatus || !$currentStatus->canTransitionTo($newStatus)) {
			$this->getPresenter()->flashMessage(
				$this->translator->translate('messages.category.flash.status_change_invalid'),
				'error',
			);
			$this->ajaxRedirect();

			return;
		}

		$updateData = [
			'status' => $newStatus->value,
			'updated_at' => new \DateTime(),
		];

		if ($newStatus === CategoryStatus::InProgress) {
			$updateData['started_at'] = new \DateTime();
		} elseif ($newStatus === CategoryStatus::Completed) {
			$updateData['completed_at'] = new \DateTime();
		}

		$this->categoryRepository->update($id, $updateData);

		// Move to end of new status group + reindex old group
		$newOrder = $this->categoryRepository->getNextDisplayOrder(
			$category->project_id,
			$category->parent_id,
			$newStatus->value,
		);
		$this->categoryRepository->update($id, ['display_order' => $newOrder]);

		$this->categoryRepository->reindexDisplayOrders(
			$category->project_id,
			$category->parent_id,
			$category->status,
		);

		$this->getPresenter()->flashMessage(
			$this->translator->translate('messages.category.flash.status_changed'),
			'success',
		);
		$this->ajaxRedirect();
	}

	public function handleDeleteAttachment(int $id): void
	{
		$attachment = $this->attachmentRepository->findById($id);
		if (!$attachment) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.not_found'),
				404,
			);
		}

		// Verify attachment belongs to this project
		$item = $this->itemRepository->findById($attachment->item_id);
		if (!$item) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.not_found'),
				404,
			);
		}

		$category = $this->categoryRepository->findById($item->category_id);
		if (!$category || $category->project_id !== $this->projectId) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.not_found'),
				404,
			);
		}

		// Check: must be project owner or attachment uploader
		$userId = $this->getPresenter()->getUser()->getId();
		$isUploader = $attachment->uploaded_by_id === $userId;

		if (!$this->isOwner && !$isUploader) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.forbidden'),
				403,
			);
		}

		try {
			$this->attachmentService->deleteAttachment($attachment);
			$this->getPresenter()->flashMessage(
				$this->translator->translate('messages.attachment.flash.deleted'),
				'success',
			);
		} catch (\RuntimeException) {
			$this->getPresenter()->flashMessage(
				$this->translator->translate('messages.attachment.flash.delete_failed'),
				'error',
			);
		}

		$this->ajaxRedirect();
	}

	public function handleDeleteItem(int $id): void
	{
		$this->ensureOwner();

		$item = $this->itemRepository->findById($id);
		if (!$item) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.not_found'),
				404,
			);
		}

		// Verify item belongs to this project via its category
		$category = $this->categoryRepository->findById($item->category_id);
		if (!$category || $category->project_id !== $this->projectId) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.not_found'),
				404,
			);
		}

		// Delete triggers recalculate automatically via ItemRepository
		$this->itemRepository->delete($id);

		$this->getPresenter()->flashMessage(
			$this->translator->translate('messages.item.flash.deleted'),
			'success',
		);
		$this->ajaxRedirect();
	}

	// ── Category form signals ──────────────────────────────────────────

	public function handleShowCategoryForm(?int $parentId = null): void
	{
		$this->ensureOwner();

		// Validate parent if provided
		if ($parentId !== null) {
			$parent = $this->categoryRepository->findById($parentId);
			if (!$parent || $parent->project_id !== $this->projectId) {
				$this->getPresenter()->error(
					$this->translator->translate('messages.error.not_found'),
					404,
				);
			}
			if ($parent->parent_id !== null) {
				$this->getPresenter()->error(
					$this->translator->translate('messages.error.forbidden'),
					403,
				);
			}
		}

		$this->showCategoryForm = true;
		$this->categoryParentId = $parentId;
		$this->editCategoryId = null;

		$form = $this->getComponent('categoryForm');
		$form->setDefaults([
			'editId' => '',
			'parentId' => $parentId ?? '',
			'status' => CategoryStatus::Planned->value,
		]);

		$this->redrawControl('categoryFormModal');
	}

	public function handleEditCategory(int $id): void
	{
		$this->ensureOwner();
		$category = $this->loadCategory($id);

		$this->showCategoryForm = true;
		$this->editCategoryId = $id;
		$this->categoryParentId = $category->parent_id;

		$form = $this->getComponent('categoryForm');
		$form->setDefaults([
			'editId' => $id,
			'parentId' => $category->parent_id ?? '',
			'name' => $category->name,
			'description' => $category->description,
			'status' => $category->status,
			'estimatedAmount' => $category->estimated_amount,
			'manualAmountOverride' => (bool) $category->manual_amount_override,
			'actualAmount' => $category->actual_amount,
		]);

		$this->redrawControl('categoryFormModal');
	}

	protected function createComponentCategoryForm(): Form
	{
		$form = new Form();
		$form->addProtection();

		$form->addHidden('editId');
		$form->addHidden('parentId');

		$form->addText('name', $this->translator->translate('messages.category.form.name'))
			->setRequired()
			->setMaxLength(200);

		$form->addTextArea('description', $this->translator->translate('messages.category.form.description'))
			->setHtmlAttribute('rows', 3);

		$form->addSelect('status', $this->translator->translate('messages.category.form.status'), CategoryStatus::formOptions())
			->setRequired()
			->setDefaultValue(CategoryStatus::Planned->value);

		$form->addText('estimatedAmount', $this->translator->translate('messages.category.form.estimated_amount'))
			->setHtmlType('number')
			->setHtmlAttribute('step', '0.01')
			->setHtmlAttribute('min', '0');

		$form->addCheckbox('manualAmountOverride', $this->translator->translate('messages.category.form.manual_amount_override'));

		$form->addText('actualAmount', $this->translator->translate('messages.category.form.manual_amount'))
			->setHtmlType('number')
			->setHtmlAttribute('step', '0.01')
			->setHtmlAttribute('min', '0');

		$form->addSubmit('send');

		$form->onSuccess[] = $this->categoryFormSucceeded(...);

		$form->onError[] = function () {
			$this->showCategoryForm = true;
			if ($this->getPresenter()->isAjax()) {
				$this->redrawControl('categoryFormModal');
			}
		};

		return $form;
	}

	private function categoryFormSucceeded(Form $form, \stdClass $data): void
	{
		$editId = $data->editId !== '' ? (int) $data->editId : null;
		$parentId = $data->parentId !== '' ? (int) $data->parentId : null;

		$values = [
			'name' => $data->name,
			'description' => $data->description ?: null,
			'status' => $data->status,
			'estimated_amount' => $data->estimatedAmount !== '' && $data->estimatedAmount !== null
				? (string) $data->estimatedAmount
				: null,
			'manual_amount_override' => $data->manualAmountOverride ? 1 : 0,
			'updated_at' => new \DateTime(),
		];

		if ($data->manualAmountOverride) {
			$values['actual_amount'] = $data->actualAmount !== '' && $data->actualAmount !== null
				? (string) $data->actualAmount
				: null;
		}

		if ($editId) {
			// Edit mode
			$category = $this->loadCategory($editId);
			$oldStatus = $category->status;

			if ($data->status !== $oldStatus) {
				$newStatus = CategoryStatus::from($data->status);
				if ($newStatus === CategoryStatus::InProgress && !$category->started_at) {
					$values['started_at'] = new \DateTime();
				} elseif ($newStatus === CategoryStatus::Completed) {
					$values['completed_at'] = new \DateTime();
				}
			}

			$this->categoryRepository->update($editId, $values);

			if ($data->status !== $oldStatus) {
				$newOrder = $this->categoryRepository->getNextDisplayOrder(
					$this->projectId,
					$category->parent_id,
					$data->status,
				);
				$this->categoryRepository->update($editId, ['display_order' => $newOrder]);
				$this->categoryRepository->reindexDisplayOrders($this->projectId, $category->parent_id, $oldStatus);
			}

			$this->amountCalculator->recalculate($editId);

			$this->getPresenter()->flashMessage(
				$this->translator->translate('messages.category.flash.updated'),
				'success',
			);
		} else {
			// Create mode
			$values['project_id'] = $this->projectId;
			$values['parent_id'] = $parentId;
			$values['created_at'] = new \DateTime();
			$values['display_order'] = $this->categoryRepository->getNextDisplayOrder(
				$this->projectId,
				$parentId,
				$data->status,
			);
			unset($values['updated_at']);

			$category = $this->categoryRepository->insert($values);

			if ($parentId) {
				$this->amountCalculator->recalculate($parentId);
			} else {
				$this->amountCalculator->recalculateProjectTotal($this->projectId);
			}

			$this->getPresenter()->flashMessage(
				$this->translator->translate('messages.category.flash.created'),
				'success',
			);
		}

		$this->showCategoryForm = false;
		$this->redrawControl('categoryFormModal');
		$this->ajaxRedirect();
	}

	// ── Item form signals ───────────────────────────────────────────────

	public function handleShowItemForm(int $categoryId): void
	{
		$category = $this->categoryRepository->findById($categoryId);
		if (!$category || $category->project_id !== $this->projectId) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.not_found'),
				404,
			);
		}

		// Only owner can add items to completed categories
		if ($category->status === 'completed' && !$this->isOwner) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.forbidden'),
				403,
			);
		}

		$this->showItemForm = true;
		$this->itemCategoryId = $categoryId;
		$this->editItemId = null;

		$form = $this->getComponent('itemForm');
		$form->setDefaults([
			'editId' => '',
			'categoryId' => $categoryId,
			'itemDate' => date('Y-m-d'),
		]);

		$this->redrawControl('itemFormModal');
	}

	public function handleEditItem(int $id): void
	{
		$this->ensureOwner();

		$item = $this->itemRepository->findById($id);
		if (!$item) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.not_found'),
				404,
			);
		}

		$category = $this->categoryRepository->findById($item->category_id);
		if (!$category || $category->project_id !== $this->projectId) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.not_found'),
				404,
			);
		}

		$this->showItemForm = true;
		$this->editItemId = $id;
		$this->itemCategoryId = $item->category_id;

		$form = $this->getComponent('itemForm');
		$defaults = [
			'editId' => $id,
			'categoryId' => $item->category_id,
			'description' => $item->description,
			'itemDate' => $item->item_date instanceof \DateTimeInterface
				? $item->item_date->format('Y-m-d')
				: (string) $item->item_date,
			'weather' => $item->weather,
		];

		if ($this->canEditAmount) {
			$defaults['amount'] = $item->amount;
		}
		if ($this->canEditFlags) {
			$defaults['isControlDay'] = (bool) $item->is_control_day;
			$defaults['includeInConstructionLog'] = (bool) $item->include_in_construction_log;
		}

		$form->setDefaults($defaults);

		$this->redrawControl('itemFormModal');
	}

	protected function createComponentItemForm(): Form
	{
		$form = new Form();
		$form->addProtection();

		$form->addHidden('editId');
		$form->addHidden('categoryId');

		$form->addTextArea('description', $this->translator->translate('messages.item.form.description'))
			->setRequired()
			->setHtmlAttribute('rows', 5);

		$form->addText('itemDate', $this->translator->translate('messages.item.form.item_date'))
			->setHtmlType('date')
			->setRequired()
			->setDefaultValue(date('Y-m-d'));

		$form->addText('weather', $this->translator->translate('messages.item.form.weather'))
			->setMaxLength(500);

		if ($this->canEditAmount) {
			$form->addText('amount', $this->translator->translate('messages.item.form.amount'))
				->setHtmlType('number')
				->setHtmlAttribute('step', '0.01');
		}

		if ($this->canEditFlags) {
			$form->addCheckbox('isControlDay', $this->translator->translate('messages.item.form.is_control_day'));
			$form->addCheckbox('includeInConstructionLog', $this->translator->translate('messages.item.form.include_in_construction_log'));
		}

		$form->addMultiUpload('attachmentFiles', $this->translator->translate('messages.item.form.attachments'))
			->addRule(Form::MaxFileSize, $this->translator->translate('messages.validators.attachment.max_size'), 10 * 1024 * 1024)
			->addRule(Form::MimeType, $this->translator->translate('messages.validators.attachment.invalid_type'), [
				'image/jpeg', 'image/png', 'image/gif', 'image/webp',
				'application/pdf',
				'application/vnd.ms-excel',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/msword',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/zip', 'application/x-zip-compressed',
				'application/vnd.oasis.opendocument.text',
				'application/vnd.oasis.opendocument.spreadsheet',
				'application/vnd.oasis.opendocument.presentation',
			]);

		$form->addSubmit('send');

		$form->onSuccess[] = $this->itemFormSucceeded(...);

		$form->onError[] = function () {
			$this->showItemForm = true;
			if ($this->getPresenter()->isAjax()) {
				$this->redrawControl('itemFormModal');
			}
		};

		return $form;
	}

	private function itemFormSucceeded(Form $form, \stdClass $data): void
	{
		$editId = $data->editId !== '' ? (int) $data->editId : null;
		$categoryId = (int) $data->categoryId;

		$values = [
			'description' => $data->description,
			'item_date' => $data->itemDate,
			'weather' => $data->weather ?: null,
		];

		if ($this->canEditAmount && isset($data->amount)) {
			$values['amount'] = $data->amount !== ''
				? (string) $data->amount
				: null;
		}

		if ($this->canEditFlags) {
			$values['is_control_day'] = isset($data->isControlDay) && $data->isControlDay ? 1 : 0;
			$values['include_in_construction_log'] = isset($data->includeInConstructionLog) && $data->includeInConstructionLog ? 1 : 0;
		}

		if ($editId) {
			// Edit mode
			$values['updated_at'] = new \DateTime();
			$this->itemRepository->update($editId, $values);
			$itemRow = $this->itemRepository->findById($editId);

			$this->getPresenter()->flashMessage(
				$this->translator->translate('messages.item.flash.updated'),
				'success',
			);
		} else {
			// Create mode
			$values['category_id'] = $categoryId;
			$values['created_by_id'] = $this->getPresenter()->getUser()->getId();
			$values['created_at'] = new \DateTime();

			$itemRow = $this->itemRepository->insert($values);

			$this->getPresenter()->flashMessage(
				$this->translator->translate('messages.item.flash.created'),
				'success',
			);
		}

		// Process uploaded attachments
		/** @var \Nette\Http\FileUpload[] $files */
		$files = $data->attachmentFiles ?? [];
		foreach ($files as $file) {
			if ($file->isOk()) {
				try {
					$this->attachmentService->uploadAttachment(
						$file,
						$itemRow->id,
						$categoryId,
						$this->projectId,
						$this->getPresenter()->getUser()->getId(),
					);
				} catch (\RuntimeException $e) {
					$this->getPresenter()->flashMessage($e->getMessage(), 'warning');
				}
			}
		}

		$this->showItemForm = false;
		$this->redrawControl('itemFormModal');
		$this->ajaxRedirect();
	}

	/**
	 * AJAX-aware redirect: redraw snippets for AJAX requests, redirect for standard requests.
	 */
	private function ajaxRedirect(): void
	{
		$presenter = $this->getPresenter();
		if ($presenter->isAjax()) {
			$this->redrawControl('categoryTree');
			$presenter->redrawControl('flashes');
			$presenter->redrawControl('projectBudget');
		} else {
			$presenter->redirect('this');
		}
	}

	private function ensureOwner(): void
	{
		if (!$this->isOwner) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.forbidden'),
				403,
			);
		}
	}

	private function loadCategory(int $id): ActiveRow
	{
		$category = $this->categoryRepository->findById($id);
		if (!$category || $category->project_id !== $this->projectId) {
			$this->getPresenter()->error(
				$this->translator->translate('messages.error.not_found'),
				404,
			);
		}

		return $category;
	}
}
