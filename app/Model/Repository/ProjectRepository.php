<?php

declare(strict_types=1);

namespace App\Model\Repository;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class ProjectRepository
{
	public function __construct(
		private Explorer $database,
	) {}

	public function getTable(): Selection
	{
		return $this->database->table('project');
	}

	public function findById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}

	/**
	 * Find all projects where user is a member, ordered by status then created_at DESC.
	 *
	 * @return array<\Nette\Database\Row>
	 */
	public function findByUser(int $userId): array
	{
		return $this->database->query('
			SELECT p.*,
				pm.roles AS member_roles,
				CASE p.status
					WHEN ? THEN 1
					WHEN ? THEN 2
					WHEN ? THEN 3
					WHEN ? THEN 4
					WHEN ? THEN 5
					ELSE 6
				END AS status_order
			FROM project p
			INNER JOIN project_member pm ON pm.project_id = p.id
			WHERE pm.user_id = ?
			ORDER BY status_order ASC, p.created_at DESC
		', 'planning', 'active', 'paused', 'completed', 'cancelled', $userId)->fetchAll();
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

	public function getCategoryCount(int $projectId): int
	{
		try {
			return $this->database->table('category')
				->where('project_id', $projectId)
				->count('*');
		} catch (\Nette\InvalidArgumentException) {
			// Table doesn't exist yet (will be created in category phase)
			return 0;
		}
	}
}
