<?php

declare(strict_types=1);

namespace App\Model\Security;

final class PwnedPasswordChecker
{
	/**
	 * Check if a password has been compromised using the HIBP API (k-anonymity).
	 * Returns true if the password was found in known breaches.
	 */
	public function isCompromised(string $password): bool
	{
		$hash = strtoupper(sha1($password));
		$prefix = substr($hash, 0, 5);
		$suffix = substr($hash, 5);

		$url = 'https://api.pwnedpasswords.com/range/' . $prefix;

		$context = stream_context_create([
			'http' => [
				'timeout' => 3,
				'header' => "User-Agent: STDozor-Nette/1.0\r\n",
			],
		]);

		$response = @file_get_contents($url, false, $context);

		if ($response === false) {
			// API unavailable — don't block registration
			return false;
		}

		foreach (explode("\n", $response) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}

			[$hashSuffix] = explode(':', $line, 2);
			if (strcasecmp($hashSuffix, $suffix) === 0) {
				return true;
			}
		}

		return false;
	}
}
