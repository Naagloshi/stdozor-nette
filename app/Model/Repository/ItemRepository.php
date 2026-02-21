<?php

declare(strict_types=1);

namespace App\Model\Repository;

use App\Model\Service\CategoryAmountCalculator;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


final class ItemRepository
{
	public function __construct(
		private Explorer $database,
		private CategoryAmountCalculator $amountCalculator,
	) {
	}


	public function getTable(): Selection
	{
		return $this->database->table('item');
	}


	public function findById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}


	/**
	 * Find all items for a category, sorted by item_date DESC, id DESC.
	 * @return ActiveRow[]
	 */
	public function findByCategory(int $categoryId): array
	{
		return $this->getTable()
			->where('category_id', $categoryId)
			->order('item_date ASC, id ASC')
			->fetchAll();
	}


	/**
	 * Batch load items for multiple categories at once.
	 * @param int[] $categoryIds
	 * @return array<int, ActiveRow[]> keyed by category_id
	 */
	public function findByCategoryIds(array $categoryIds): array
	{
		if (empty($categoryIds)) {
			return [];
		}

		$rows = $this->getTable()
			->where('category_id', $categoryIds)
			->order('item_date ASC, id ASC')
			->fetchAll();

		$result = [];
		foreach ($rows as $row) {
			$result[$row->category_id][] = $row;
		}

		return $result;
	}


	/**
	 * Insert with automatic amount recalculation.
	 */
	public function insert(array $data): ActiveRow
	{
		$row = $this->getTable()->insert($data);
		$this->amountCalculator->recalculate($row->category_id);
		return $row;
	}


	/**
	 * Update with automatic amount recalculation.
	 */
	public function update(int $id, array $data): void
	{
		$item = $this->findById($id);
		$this->getTable()->where('id', $id)->update($data);
		if ($item) {
			$this->amountCalculator->recalculate($item->category_id);
		}
	}


	/**
	 * Delete with automatic amount recalculation.
	 */
	public function delete(int $id): void
	{
		$item = $this->findById($id);
		if (!$item) {
			return;
		}

		$categoryId = $item->category_id;
		$this->getTable()->where('id', $id)->delete();
		$this->amountCalculator->recalculate($categoryId);
	}


	public function countByCategory(int $categoryId): int
	{
		return $this->getTable()
			->where('category_id', $categoryId)
			->count('*');
	}
}
