<?php

declare(strict_types=1);

namespace App\Model\Repository;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class ProjectMemberRepository
{
	public function __construct(
		private Explorer $database,
	) {}

	public function getTable(): Selection
	{
		return $this->database->table('project_member');
	}

	public function findByProjectAndUser(int $projectId, int $userId): ?ActiveRow
	{
		return $this->getTable()
			->where('project_id', $projectId)
			->where('user_id', $userId)
			->fetch() ?: null;
	}

	/**
	 * Create project owner membership.
	 */
	public function createOwner(int $projectId, int $userId): ActiveRow
	{
		return $this->getTable()->insert([
			'project_id' => $projectId,
			'user_id' => $userId,
			'roles' => json_encode(['owner']),
			'has_global_category_access' => 1,
			'invited_at' => new \DateTime(),
			'accepted_at' => new \DateTime(),
		]);
	}

	/**
	 * Check if user is owner of the project.
	 */
	public function isOwner(int $projectId, int $userId): bool
	{
		$member = $this->findByProjectAndUser($projectId, $userId);
		if (!$member) {
			return false;
		}

		$roles = json_decode($member->roles, true) ?: [];

		return in_array('owner', $roles, true);
	}

	/**
	 * Get decoded roles for a member.
	 *
	 * @return string[]
	 */
	public function getRoles(ActiveRow $member): array
	{
		return json_decode($member->roles, true) ?: [];
	}

	public function findById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}

	/**
	 * Find all members of a project with user email.
	 *
	 * @return ActiveRow[]
	 */
	public function findByProject(int $projectId): array
	{
		return $this->getTable()
			->where('project_id', $projectId)
			->order('invited_at ASC')
			->fetchAll();
	}

	/**
	 * Count members with owner role in a project.
	 */
	public function countOwners(int $projectId): int
	{
		return $this->database->query(
			'SELECT COUNT(*) AS cnt FROM project_member WHERE project_id = ? AND JSON_CONTAINS(roles, ?)',
			$projectId,
			'"owner"',
		)->fetch()->cnt;
	}

	/**
	 * Check if user is the permanent owner (project.owner_id).
	 */
	public function isPermanentOwner(int $projectId, int $userId): bool
	{
		$project = $this->database->table('project')->get($projectId);

		return $project && $project->owner_id === $userId;
	}

	/**
	 * Merge new roles into existing member's roles.
	 *
	 * @param string[] $newRoles
	 */
	public function addRoles(int $memberId, array $newRoles): void
	{
		$member = $this->findById($memberId);
		if (!$member) {
			return;
		}

		$existing = json_decode($member->roles, true) ?: [];
		$merged = array_values(array_unique(array_merge($existing, $newRoles)));

		$this->getTable()->where('id', $memberId)->update([
			'roles' => json_encode($merged),
		]);
	}

	/**
	 * Replace all roles for a member.
	 *
	 * @param string[] $roles
	 */
	public function updateRoles(int $memberId, array $roles): void
	{
		$this->getTable()->where('id', $memberId)->update([
			'roles' => json_encode(array_values($roles)),
		]);
	}

	/**
	 * Update global category access flag.
	 */
	public function updateGlobalAccess(int $memberId, bool $hasGlobalAccess): void
	{
		$this->getTable()->where('id', $memberId)->update([
			'has_global_category_access' => $hasGlobalAccess ? 1 : 0,
		]);
	}

	/**
	 * Create a new member from invitation.
	 *
	 * @param string[] $roles
	 */
	public function createMember(
		int $projectId,
		int $userId,
		array $roles,
		bool $hasGlobalCategoryAccess,
		?int $invitedById = null,
	): ActiveRow {
		return $this->getTable()->insert([
			'project_id' => $projectId,
			'user_id' => $userId,
			'roles' => json_encode($roles),
			'has_global_category_access' => $hasGlobalCategoryAccess ? 1 : 0,
			'invited_by_id' => $invitedById,
			'invited_at' => new \DateTime(),
			'accepted_at' => new \DateTime(),
		]);
	}

	public function delete(int $id): void
	{
		$this->getTable()->where('id', $id)->delete();
	}
}
