<?php

declare(strict_types=1);

namespace App\Model\Repository;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class CategoryPermissionRepository
{
	public function __construct(
		private Explorer $database,
	) {}

	public function getTable(): Selection
	{
		return $this->database->table('category_permission');
	}

	/**
	 * Get all permissions for a member.
	 *
	 * @return ActiveRow[]
	 */
	public function findByMember(int $projectMemberId): array
	{
		return $this->getTable()
			->where('project_member_id', $projectMemberId)
			->fetchAll();
	}

	/**
	 * Grant category access to a member.
	 */
	public function grantAccess(
		int $projectMemberId,
		int $categoryId,
		int $grantedById,
		bool $canView = true,
		bool $canEdit = true,
		bool $canDelete = false,
	): ActiveRow {
		return $this->getTable()->insert([
			'project_member_id' => $projectMemberId,
			'category_id' => $categoryId,
			'granted_by_id' => $grantedById,
			'can_view' => $canView ? 1 : 0,
			'can_edit' => $canEdit ? 1 : 0,
			'can_delete' => $canDelete ? 1 : 0,
			'granted_at' => new \DateTime(),
		]);
	}

	/**
	 * Revoke all category permissions for a member.
	 */
	public function revokeAllForMember(int $projectMemberId): void
	{
		$this->getTable()
			->where('project_member_id', $projectMemberId)
			->delete();
	}

	/**
	 * Get flat array of category IDs that a member has access to.
	 *
	 * @return int[]
	 */
	public function getCategoryIdsForMember(int $projectMemberId): array
	{
		return $this->getTable()
			->where('project_member_id', $projectMemberId)
			->fetchPairs(null, 'category_id');
	}
}
