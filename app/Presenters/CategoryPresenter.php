<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Model\Enum\CategoryStatus;
use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use App\Model\Service\CategoryAmountCalculator;
use Contributte\Translation\Translator;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

final class CategoryPresenter extends BasePresenter
{
	private ?ActiveRow $project = null;

	private ?ActiveRow $category = null;

	private ?int $parentId = null;

	public function __construct(
		private CategoryRepository $categoryRepository,
		private ProjectRepository $projectRepository,
		private ProjectMemberRepository $memberRepository,
		private CategoryAmountCalculator $amountCalculator,
		private Translator $translator,
	) {}

	public function actionCreate(int $projectId, ?int $parentId = null): void
	{
		$this->requireLogin();

		$this->project = $this->projectRepository->findById($projectId);
		if (!$this->project) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		if (!$this->memberRepository->isOwner($projectId, $this->getUser()->getId())) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$this->parentId = $parentId;
		if ($parentId !== null) {
			$parent = $this->categoryRepository->findById($parentId);
			if (!$parent || $parent->project_id !== $projectId) {
				$this->error($this->translator->translate('messages.error.not_found'), 404);
			}
			// Limit to 2 levels — subcategory cannot have children
			if ($parent->parent_id !== null) {
				$this->error($this->translator->translate('messages.error.forbidden'), 403);
			}
		}
	}

	public function renderCreate(int $projectId, ?int $parentId = null): void
	{
		$this->template->project = $this->project;
		$this->template->parentId = $this->parentId;
		if ($this->parentId) {
			$this->template->parentCategory = $this->categoryRepository->findById($this->parentId);
		}
	}

	public function actionEdit(int $id): void
	{
		$this->requireLogin();

		$this->category = $this->categoryRepository->findById($id);
		if (!$this->category) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$this->project = $this->projectRepository->findById($this->category->project_id);

		if (!$this->memberRepository->isOwner($this->category->project_id, $this->getUser()->getId())) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$form = $this->getComponent('categoryForm');
		$form->setDefaults([
			'name' => $this->category->name,
			'description' => $this->category->description,
			'status' => $this->category->status,
			'estimatedAmount' => $this->category->estimated_amount,
			'manualAmountOverride' => (bool) $this->category->manual_amount_override,
			'actualAmount' => $this->category->actual_amount,
		]);
	}

	public function renderEdit(int $id): void
	{
		$this->template->category = $this->category;
		$this->template->project = $this->project;
	}

	protected function createComponentCategoryForm(): Form
	{
		$form = new Form();
		$form->addProtection();

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

		return $form;
	}

	private function categoryFormSucceeded(Form $form, \stdClass $data): void
	{
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

		// Only set actual_amount if manual override is enabled
		if ($data->manualAmountOverride) {
			$values['actual_amount'] = $data->actualAmount !== '' && $data->actualAmount !== null
				? (string) $data->actualAmount
				: null;
		}

		if ($this->category) {
			// Edit mode
			$oldStatus = $this->category->status;
			$projectId = $this->category->project_id;

			// Handle status change timestamps
			if ($data->status !== $oldStatus) {
				$newStatus = CategoryStatus::from($data->status);
				if ($newStatus === CategoryStatus::InProgress && !$this->category->started_at) {
					$values['started_at'] = new \DateTime();
				} elseif ($newStatus === CategoryStatus::Completed) {
					$values['completed_at'] = new \DateTime();
				}
			}

			$this->categoryRepository->update($this->category->id, $values);

			// If status changed, reindex display orders
			if ($data->status !== $oldStatus) {
				$newOrder = $this->categoryRepository->getNextDisplayOrder(
					$projectId,
					$this->category->parent_id,
					$data->status,
				);
				$this->categoryRepository->update($this->category->id, ['display_order' => $newOrder]);
				$this->categoryRepository->reindexDisplayOrders($projectId, $this->category->parent_id, $oldStatus);
			}

			// Recalculate amounts
			$this->amountCalculator->recalculate($this->category->id);

			$this->flashMessage(
				$this->translator->translate('messages.category.flash.updated'),
				'success',
			);
			$this->redirect('Project:show', $projectId);
		} else {
			// Create mode
			$values['project_id'] = $this->project->id;
			$values['parent_id'] = $this->parentId;
			$values['created_at'] = new \DateTime();
			$values['display_order'] = $this->categoryRepository->getNextDisplayOrder(
				$this->project->id,
				$this->parentId,
				$data->status,
			);
			unset($values['updated_at']);

			$category = $this->categoryRepository->insert($values);

			// Recalculate parent/project amounts
			if ($this->parentId) {
				$this->amountCalculator->recalculate($this->parentId);
			} else {
				$this->amountCalculator->recalculateProjectTotal($this->project->id);
			}

			$this->flashMessage(
				$this->translator->translate('messages.category.flash.created'),
				'success',
			);
			$this->redirect('Project:show', $this->project->id);
		}
	}
}
