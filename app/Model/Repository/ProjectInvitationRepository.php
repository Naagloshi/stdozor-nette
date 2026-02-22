<?php

declare(strict_types=1);

namespace App\Model\Repository;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


final class ProjectInvitationRepository
{
	public function __construct(
		private Explorer $database,
	) {
	}


	public function getTable(): Selection
	{
		return $this->database->table('project_invitation');
	}


	public function findById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}


	public function findByToken(string $token): ?ActiveRow
	{
		return $this->getTable()
			->where('token', $token)
			->fetch() ?: null;
	}


	/**
	 * Find pending (valid) invitation for email+project combination.
	 */
	public function findPendingByEmailAndProject(string $email, int $projectId): ?ActiveRow
	{
		return $this->getTable()
			->where('email', $email)
			->where('project_id', $projectId)
			->where('used', 0)
			->where('accepted_at', null)
			->where('expires_at > NOW()')
			->fetch() ?: null;
	}


	/**
	 * Find all pending invitations for a project.
	 * @return ActiveRow[]
	 */
	public function findPendingByProject(int $projectId): array
	{
		return $this->getTable()
			->where('project_id', $projectId)
			->where('used', 0)
			->where('accepted_at', null)
			->where('expires_at > NOW()')
			->order('created_at DESC')
			->fetchAll();
	}


	public function insert(array $data): ActiveRow
	{
		return $this->getTable()->insert($data);
	}


	public function update(int $id, array $data): void
	{
		$this->getTable()->where('id', $id)->update($data);
	}


	public function delete(int $id): void
	{
		$this->getTable()->where('id', $id)->delete();
	}
}
