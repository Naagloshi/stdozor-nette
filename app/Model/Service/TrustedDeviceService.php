<?php

declare(strict_types=1);

namespace App\Model\Service;

use Nette\Http\IRequest;
use Nette\Http\IResponse;

final class TrustedDeviceService
{
	private const LIFETIME = 2_592_000; // 30 days

	private const COOKIE_NAME = 'stdozor_trusted';

	public function __construct(
		private IRequest $httpRequest,
		private IResponse $httpResponse,
		private string $secret,
	) {}

	/**
	 * Create a trusted device cookie for the given user.
	 */
	public function createTrustedCookie(int $userId, int $trustedVersion): void
	{
		$expiry = time() + self::LIFETIME;
		$payload = $userId . ':' . $trustedVersion . ':' . $expiry;
		$signature = hash_hmac('sha256', $payload, $this->secret);
		$cookieValue = base64_encode($payload . ':' . $signature);

		$this->httpResponse->setCookie(
			self::COOKIE_NAME,
			$cookieValue,
			$expiry,
			'/',
			null,
			null,
			true, // httpOnly
		);
	}

	/**
	 * Check if the current request has a valid trusted device cookie.
	 */
	public function isTrusted(int $userId, int $trustedVersion): bool
	{
		$cookieValue = $this->httpRequest->getCookie(self::COOKIE_NAME);
		if ($cookieValue === null) {
			return false;
		}

		$decoded = base64_decode($cookieValue, true);
		if ($decoded === false) {
			return false;
		}

		$parts = explode(':', $decoded);
		if (count($parts) !== 4) {
			return false;
		}

		[$storedUserId, $storedVersion, $storedExpiry, $storedSignature] = $parts;

		// Verify signature
		$payload = $storedUserId . ':' . $storedVersion . ':' . $storedExpiry;
		$expectedSignature = hash_hmac('sha256', $payload, $this->secret);
		if (!hash_equals($expectedSignature, $storedSignature)) {
			return false;
		}

		// Verify expiry
		if ((int) $storedExpiry < time()) {
			return false;
		}

		// Verify user ID and trusted version
		if ((int) $storedUserId !== $userId || (int) $storedVersion !== $trustedVersion) {
			return false;
		}

		return true;
	}

	/**
	 * Remove the trusted device cookie.
	 */
	public function removeCookie(): void
	{
		$this->httpResponse->deleteCookie(self::COOKIE_NAME);
	}
}
