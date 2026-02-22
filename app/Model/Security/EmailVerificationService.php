<?php

declare(strict_types=1);

namespace App\Model\Security;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;

final class EmailVerificationService
{
	public function __construct(
		private Explorer $database,
	) {}

	/**
	 * Create a verification token for the given user.
	 *
	 * @return string the 64-char hex token
	 */
	public function createToken(int $userId): string
	{
		$token = bin2hex(random_bytes(32));

		$this->database->table('email_verification_token')->insert([
			'token' => $token,
			'user_id' => $userId,
			'expires_at' => new \DateTime('+1 hour'),
			'used' => 0,
			'created_at' => new \DateTime(),
		]);

		return $token;
	}

	/**
	 * Verify a token and mark the user as verified.
	 *
	 * @return ActiveRow the user row
	 *
	 * @throws \RuntimeException on invalid/expired/used token
	 */
	public function verify(string $tokenString): ActiveRow
	{
		$token = $this->database->table('email_verification_token')
			->where('token', $tokenString)
			->fetch();

		if (!$token) {
			throw new \RuntimeException('security.email_verification.invalid_link');
		}

		if ((bool) $token->used) {
			throw new \RuntimeException('security.email_verification.invalid_link');
		}

		if ($token->expires_at < new \DateTime()) {
			throw new \RuntimeException('security.email_verification.invalid_link');
		}

		// Mark token as used
		$token->update([
			'used' => 1,
			'used_at' => new \DateTime(),
		]);

		// Mark user as verified
		$user = $token->ref('user', 'user_id');
		$this->database->table('user')->where('id', $user->id)->update([
			'is_verified' => 1,
		]);

		return $user;
	}
}
