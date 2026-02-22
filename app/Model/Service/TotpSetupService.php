<?php

declare(strict_types=1);

namespace App\Model\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use OTPHP\TOTP;

final class TotpSetupService
{
	private const ISSUER = 'STDozor';

	public function generateSecret(): string
	{
		$totp = TOTP::generate();

		return $totp->getSecret();
	}

	/**
	 * @return string base64 data URI of QR code PNG
	 */
	public function getQrCodeDataUri(string $email, string $secret): string
	{
		$totp = TOTP::createFromSecret($secret);
		$totp->setLabel($email);
		$totp->setIssuer(self::ISSUER);
		$totp->setPeriod(30);
		$totp->setDigits(6);
		$totp->setDigest('sha1');

		$uri = $totp->getProvisioningUri();

		$builder = new Builder(
			writer: new PngWriter(),
			data: $uri,
			encoding: new Encoding('UTF-8'),
			errorCorrectionLevel: ErrorCorrectionLevel::Medium,
			size: 250,
			margin: 10,
		);
		$result = $builder->build();

		return $result->getDataUri();
	}

	public function verifyCode(string $secret, string $code): bool
	{
		$totp = TOTP::createFromSecret($secret);
		$totp->setLabel('verify');
		$totp->setIssuer(self::ISSUER);
		$totp->setPeriod(30);
		$totp->setDigits(6);
		$totp->setDigest('sha1');

		return $totp->verify($code, null, 1); // 1-step leeway (±30 seconds)
	}
}
