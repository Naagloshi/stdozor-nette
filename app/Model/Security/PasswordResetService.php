<?php

declare(strict_types=1);

namespace App\Model\Security;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;

final class PasswordResetService
{
	public function __construct(
		private Explorer $database,
	) {}

	/**
	 * Create a password reset token (selector/verifier pattern).
	 *
	 * @return string full token (selector + verifier) for use in URL
	 */
	public function createResetToken(int $userId): string
	{
		// Delete existing tokens for this user
		$this->database->table('reset_password_request')
			->where('user_id', $userId)
			->delete();

		$selector = bin2hex(random_bytes(10)); // 20 hex chars
		$verifier = bin2hex(random_bytes(20)); // 40 hex chars

		$this->database->table('reset_password_request')->insert([
			'selector' => $selector,
			'hashed_token' => password_hash($verifier, PASSWORD_DEFAULT),
			'requested_at' => new \DateTime(),
			'expires_at' => new \DateTime('+1 hour'),
			'user_id' => $userId,
		]);

		return $selector . $verifier; // 60 chars total
	}

	/**
	 * Validate a reset token and return the associated user.
	 *
	 * @return ActiveRow|null user row, or null if token is invalid
	 */
	public function validateTokenAndFetchUser(string $fullToken): ?ActiveRow
	{
		if (strlen($fullToken) < 21) {
			return null;
		}

		$selector = substr($fullToken, 0, 20);
		$verifier = substr($fullToken, 20);

		$request = $this->database->table('reset_password_request')
			->where('selector', $selector)
			->where('expires_at > ?', new \DateTime())
			->fetch();

		if (!$request) {
			return null;
		}

		if (!password_verify($verifier, $request->hashed_token)) {
			return null;
		}

		return $request->ref('user', 'user_id');
	}

	/**
	 * Remove the reset request after successful password change.
	 */
	public function removeResetRequest(string $fullToken): void
	{
		$selector = substr($fullToken, 0, 20);

		$this->database->table('reset_password_request')
			->where('selector', $selector)
			->delete();
	}
}
