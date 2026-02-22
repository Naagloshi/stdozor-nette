<?php

declare(strict_types=1);

namespace App\Model\Service;

use App\Model\Repository\CategoryRepository;
use Nette\Database\Explorer;

final class CategoryAmountCalculator
{
	public function __construct(
		private CategoryRepository $categoryRepository,
		private Explorer $database,
	) {}

	/**
	 * Recalculate actual_amount for a category and propagate up to parents/project.
	 */
	public function recalculate(int $categoryId): void
	{
		$category = $this->categoryRepository->findById($categoryId);
		if (!$category) {
			return;
		}

		// Update actual_amount if not manually overridden
		if (!(bool) $category->manual_amount_override) {
			$itemsSum = $this->categoryRepository->sumItemsAmount($categoryId);
			$childrenSum = $this->categoryRepository->sumSubcategoriesAmount($categoryId);
			$newAmount = bcadd($itemsSum, $childrenSum, 2);

			$this->categoryRepository->update($categoryId, [
				'actual_amount' => $newAmount === '0.00' ? null : $newAmount,
			]);
		}

		// Propagate to parent or project
		if ($category->parent_id) {
			$this->recalculate($category->parent_id);
		} else {
			$this->recalculateProjectTotal($category->project_id);
		}
	}

	/**
	 * Recalculate project.total_amount_cents from SUM of root categories' actual_amount.
	 */
	public function recalculateProjectTotal(int $projectId): void
	{
		$row = $this->database->query(
			'SELECT COALESCE(SUM(actual_amount), 0) AS total FROM category WHERE project_id = ? AND parent_id IS NULL',
			$projectId,
		)->fetch();

		$totalDecimal = $row ? (string) $row->total : '0';
		$totalCents = (int) bcmul($totalDecimal, '100', 0);

		$this->database->query(
			'UPDATE project SET total_amount_cents = ? WHERE id = ?',
			$totalCents ?: null,
			$projectId,
		);
	}
}
