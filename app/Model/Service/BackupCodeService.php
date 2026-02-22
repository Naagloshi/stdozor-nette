<?php

declare(strict_types=1);

namespace App\Model\Service;

final class BackupCodeService
{
	/**
	 * Generate backup codes, hash them and return both plain + hashed.
	 *
	 * @return array{plain: string[], hashed: string[]} plain-text codes (show to user ONCE) + bcrypt hashes (store in DB)
	 */
	public function generateCodes(int $count = 10): array
	{
		$plainCodes = [];
		$hashedCodes = [];

		for ($i = 0; $i < $count; ++$i) {
			$code = $this->generateCode();
			$plainCodes[] = $code;
			$hashedCodes[] = password_hash($code, \PASSWORD_BCRYPT, ['cost' => 10]);
		}

		return ['plain' => $plainCodes, 'hashed' => $hashedCodes];
	}

	/**
	 * Verify a backup code against stored hashes.
	 *
	 * @param string $code code to verify
	 * @param string[] $hashedCodes stored bcrypt hashes
	 *
	 * @return int|false index of matched code, or false if not found
	 */
	public function verifyCode(string $code, array $hashedCodes): int|false
	{
		foreach ($hashedCodes as $index => $hash) {
			if (password_verify($code, $hash)) {
				return $index;
			}
		}

		return false;
	}

	/**
	 * Generate a single 8-character alphanumeric backup code in format XXXX-XXXX.
	 */
	private function generateCode(): string
	{
		$chars = '23456789abcdefghjkmnpqrstuvwxyz'; // No ambiguous chars (0/O, 1/l/I)
		$part1 = '';
		$part2 = '';

		for ($i = 0; $i < 4; ++$i) {
			$part1 .= $chars[\random_int(0, \strlen($chars) - 1)];
			$part2 .= $chars[\random_int(0, \strlen($chars) - 1)];
		}

		return $part1 . '-' . $part2;
	}
}
