<?php

declare(strict_types=1);

namespace App\Model\Repository;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class UserRepository
{
	public function __construct(
		private Explorer $database,
	) {}

	public function getTable(): Selection
	{
		return $this->database->table('user');
	}

	public function findById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}

	public function findByEmail(string $email): ?ActiveRow
	{
		return $this->getTable()->where('email', $email)->fetch() ?: null;
	}

	/**
	 * Insert new user and create an empty profile.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return ActiveRow the created user row
	 */
	public function insert(array $data): ActiveRow
	{
		$user = $this->getTable()->insert($data);
		assert($user instanceof ActiveRow);

		$this->database->table('profile')->insert([
			'user_id' => $user->id,
			'created_at' => new \DateTime(),
		]);

		return $user;
	}

	public function setVerified(int $userId): void
	{
		$this->getTable()->where('id', $userId)->update([
			'is_verified' => 1,
		]);
	}

	public function updatePassword(int $userId, string $hashedPassword): void
	{
		$this->getTable()->where('id', $userId)->update([
			'password' => $hashedPassword,
		]);
	}

	public function getProfile(int $userId): ?ActiveRow
	{
		$user = $this->findById($userId);

		return $user?->related('profile')->fetch() ?: null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateProfile(int $userId, array $data): void
	{
		$this->database->table('profile')
			->where('user_id', $userId)
			->update($data);
	}

	public function deleteUnverified(int $userId): void
	{
		// Delete profile first (FK constraint)
		$this->database->table('profile')->where('user_id', $userId)->delete();
		$this->database->table('email_verification_token')->where('user_id', $userId)->delete();
		$this->getTable()->where('id', $userId)->delete();
	}
}
