<?php

declare(strict_types=1);

namespace App\Controls\CategoryList;

use App\Model\Enum\CategoryStatus;
use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ItemRepository;
use App\Model\Service\CategoryAmountCalculator;
use Contributte\Translation\Translator;
use Nette\Application\UI\Control;
use Nette\Database\Table\ActiveRow;


final class CategoryListControl extends Control
{
	public function __construct(
		private int $projectId,
		private bool $isOwner,
		private array $memberRoles,
		private CategoryRepository $categoryRepository,
		private CategoryAmountCalculator $amountCalculator,
		private ItemRepository $itemRepository,
		private Translator $translator,
	) {
	}


	public function render(string $currency): void
	{
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

		$this->template->projectId = $this->projectId;
		$this->template->isOwner = $this->isOwner;
		$this->template->memberRoles = $this->memberRoles;
		$this->template->currency = $currency;
		$this->template->categoryCount = count($categories);
		$this->template->groupedCategories = $groupedCategories;
		$this->template->itemsByCategory = $itemsByCategory;
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
		$this->getPresenter()->redirect('this');
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
		$this->getPresenter()->redirect('this');
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
			$this->getPresenter()->redirect('this');
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
		$this->getPresenter()->redirect('this');
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
		$this->getPresenter()->redirect('this');
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
