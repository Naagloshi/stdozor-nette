<?php

declare(strict_types=1);

namespace App\Model\Repository;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class AttachmentRepository
{
	public function __construct(
		private Explorer $database,
	) {}

	public function getTable(): Selection
	{
		return $this->database->table('attachment');
	}

	public function findById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}

	/**
	 * @return ActiveRow[]
	 */
	public function findByItem(int $itemId): array
	{
		return $this->getTable()
			->where('item_id', $itemId)
			->order('uploaded_at ASC')
			->fetchAll();
	}

	/**
	 * Batch load attachments for multiple items at once.
	 *
	 * @param int[] $itemIds
	 *
	 * @return array<int, ActiveRow[]> keyed by item_id
	 */
	public function findByItemIds(array $itemIds): array
	{
		if (empty($itemIds)) {
			return [];
		}

		$rows = $this->getTable()
			->where('item_id', $itemIds)
			->order('uploaded_at ASC')
			->fetchAll();

		$result = [];
		foreach ($rows as $row) {
			$result[$row->item_id][] = $row;
		}

		return $result;
	}

	public function countByItem(int $itemId): int
	{
		return $this->getTable()
			->where('item_id', $itemId)
			->count('*');
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert(array $data): ActiveRow
	{
		return $this->getTable()->insert($data);
	}

	public function delete(int $id): void
	{
		$this->getTable()->where('id', $id)->delete();
	}

	public function deleteByItem(int $itemId): void
	{
		$this->getTable()->where('item_id', $itemId)->delete();
	}
}
