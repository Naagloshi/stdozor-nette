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
	) {
	}


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
	 * @return string[]
	 */
	public function getRoles(ActiveRow $member): array
	{
		return json_decode($member->roles, true) ?: [];
	}
}
