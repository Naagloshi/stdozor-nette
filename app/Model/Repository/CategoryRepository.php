<?php

declare(strict_types=1);

namespace App\Model\Repository;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class CategoryRepository
{
	public function __construct(
		private Explorer $database,
	) {}

	public function getTable(): Selection
	{
		return $this->database->table('category');
	}

	public function findById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert(array $data): ActiveRow
	{
		return $this->getTable()->insert($data);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void
	{
		$this->getTable()->where('id', $id)->update($data);
	}

	public function delete(int $id): void
	{
		$this->getTable()->where('id', $id)->delete();
	}

	/**
	 * Find all categories for a project, sorted by status group then display_order.
	 *
	 * @return ActiveRow[]
	 */
	public function findByProject(int $projectId): array
	{
		/** @var ActiveRow[] $result */
		$result = $this->database->query('
			SELECT * FROM category
			WHERE project_id = ?
			ORDER BY
				CASE status
					WHEN ? THEN 0
					WHEN ? THEN 1
					WHEN ? THEN 2
					ELSE 3
				END,
				display_order,
				id
		', $projectId, 'in_progress', 'planned', 'completed')->fetchAll();

		return $result;
	}

	/**
	 * Find root categories (parent_id IS NULL) for a project.
	 *
	 * @return ActiveRow[]
	 */
	public function findRootsByProject(int $projectId): array
	{
		/** @var ActiveRow[] $result */
		$result = $this->database->query('
			SELECT * FROM category
			WHERE project_id = ? AND parent_id IS NULL
			ORDER BY
				CASE status
					WHEN ? THEN 0
					WHEN ? THEN 1
					WHEN ? THEN 2
					ELSE 3
				END,
				display_order,
				id
		', $projectId, 'in_progress', 'planned', 'completed')->fetchAll();

		return $result;
	}

	/**
	 * Find direct children of a category.
	 *
	 * @return ActiveRow[]
	 */
	public function findChildren(int $categoryId): array
	{
		/** @var ActiveRow[] $result */
		$result = $this->database->query('
			SELECT * FROM category
			WHERE parent_id = ?
			ORDER BY
				CASE status
					WHEN ? THEN 0
					WHEN ? THEN 1
					WHEN ? THEN 2
					ELSE 3
				END,
				display_order,
				id
		', $categoryId, 'in_progress', 'planned', 'completed')->fetchAll();

		return $result;
	}

	/**
	 * Get next display_order value for new category in a specific status group.
	 */
	public function getNextDisplayOrder(int $projectId, ?int $parentId, string $status): int
	{
		$row = $this->database->query(
			'
			SELECT COALESCE(MAX(display_order), -1) + 1 AS next_order
			FROM category
			WHERE project_id = ? AND status = ?
				AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?'),
			...array_filter([$projectId, $status, $parentId], fn($v) => $v !== null),
		)->fetch();

		return $row ? (int) $row->next_order : 0;
	}

	/**
	 * Reindex display_orders within a status group to maintain sequential 0, 1, 2, ...
	 */
	public function reindexDisplayOrders(int $projectId, ?int $parentId, string $status): void
	{
		$parentCondition = $parentId === null ? 'parent_id IS NULL' : 'parent_id = ' . (int) $parentId;

		$categories = $this->database->query(
			"SELECT id FROM category WHERE project_id = ? AND status = ? AND $parentCondition ORDER BY display_order, id",
			$projectId,
			$status,
		)->fetchAll();

		foreach ($categories as $index => $cat) {
			$this->database->query('UPDATE category SET display_order = ? WHERE id = ?', $index, $cat->id);
		}
	}

	/**
	 * Swap display_order with neighbor in same status group.
	 *
	 * @return bool true if swap succeeded
	 */
	public function swapOrder(ActiveRow $category, string $direction): bool
	{
		$parentCondition = $category->parent_id === null
			? 'parent_id IS NULL'
			: 'parent_id = ' . (int) $category->parent_id;

		if ($direction === 'up') {
			$neighbor = $this->database->query(
				"SELECT * FROM category
				WHERE project_id = ? AND status = ? AND $parentCondition AND display_order < ?
				ORDER BY display_order DESC LIMIT 1",
				$category->project_id,
				$category->status,
				$category->display_order,
			)->fetch();
		} else {
			$neighbor = $this->database->query(
				"SELECT * FROM category
				WHERE project_id = ? AND status = ? AND $parentCondition AND display_order > ?
				ORDER BY display_order ASC LIMIT 1",
				$category->project_id,
				$category->status,
				$category->display_order,
			)->fetch();
		}

		if (!$neighbor) {
			return false;
		}

		// Swap display_order values
		$this->database->query(
			'UPDATE category SET display_order = ? WHERE id = ?',
			$neighbor->display_order,
			$category->id,
		);
		$this->database->query(
			'UPDATE category SET display_order = ? WHERE id = ?',
			$category->display_order,
			$neighbor->id,
		);

		return true;
	}

	/**
	 * Sum item amounts for a category.
	 */
	public function sumItemsAmount(int $categoryId): string
	{
		$row = $this->database->query(
			'SELECT COALESCE(SUM(amount), 0) AS total FROM item WHERE category_id = ?',
			$categoryId,
		)->fetch();

		return $row ? (string) $row->total : '0';
	}

	/**
	 * Sum actual_amount of direct child categories.
	 */
	public function sumSubcategoriesAmount(int $categoryId): string
	{
		$row = $this->database->query(
			'SELECT COALESCE(SUM(actual_amount), 0) AS total FROM category WHERE parent_id = ?',
			$categoryId,
		)->fetch();

		return $row ? (string) $row->total : '0';
	}

	/**
	 * Recursively delete category with all children.
	 *
	 * @return int|null parent_id of deleted category (for redirect)
	 */
	public function deleteRecursive(int $categoryId): ?int
	{
		$category = $this->findById($categoryId);
		if (!$category) {
			return null;
		}

		$parentId = $category->parent_id;
		$projectId = $category->project_id;
		$status = $category->status;

		// Recursive delete children first
		$children = $this->findChildren($categoryId);
		foreach ($children as $child) {
			$this->deleteRecursive($child->id);
		}

		// Delete the category itself (items cascade via FK)
		$this->delete($categoryId);

		// Reindex display_orders in the same group
		$this->reindexDisplayOrders($projectId, $parentId, $status);

		return $parentId;
	}

	/**
	 * Count categories for a project.
	 */
	public function countByProject(int $projectId): int
	{
		return $this->getTable()
			->where('project_id', $projectId)
			->count('*');
	}

	/**
	 * Build tree structure from flat array of categories.
	 *
	 * @param ActiveRow[] $categories
	 *
	 * @return array<int, array{category: ActiveRow, children: array<mixed>}> tree with 'category' and 'children' keys
	 */
	public function buildTree(array $categories): array
	{
		$indexed = [];
		$tree = [];

		// Index by id
		foreach ($categories as $cat) {
			$indexed[$cat->id] = ['category' => $cat, 'children' => []];
		}

		// Build tree
		foreach ($indexed as $id => &$node) {
			if ($node['category']->parent_id && isset($indexed[$node['category']->parent_id])) {
				$indexed[$node['category']->parent_id]['children'][] = &$node;
			} else {
				$tree[] = &$node;
			}
		}

		return $tree;
	}
}
