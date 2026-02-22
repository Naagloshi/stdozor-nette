<?php

declare(strict_types=1);

namespace App\Model\Service;

use App\Model\Repository\WebAuthnCredentialRepository;
use Cose\Algorithms;
use Nette\Database\Table\ActiveRow;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

final class WebAuthnService
{
	private CeremonyStepManagerFactory $ceremonyFactory;

	/**
	 * @param string[] $allowedOrigins
	 */
	public function __construct(
		private WebAuthnCredentialRepository $credentialRepository,
		private string $rpId,
		private string $rpName,
		private array $allowedOrigins,
	) {
		$this->ceremonyFactory = new CeremonyStepManagerFactory();
		$this->ceremonyFactory->setAllowedOrigins($this->allowedOrigins);
	}

	/**
	 * Create registration options for browser's navigator.credentials.create().
	 */
	public function createRegistrationOptions(
		int $userId,
		string $email,
		string $displayName,
		bool $isPasskey,
	): PublicKeyCredentialCreationOptions {
		$rp = PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId);
		$user = PublicKeyCredentialUserEntity::create($email, (string) $userId, $displayName);
		$challenge = random_bytes(32);

		// Exclude only same-type credentials (passkey vs 2FA) — allows same physical key for both
		$existingCredentials = $isPasskey
			? $this->credentialRepository->findPasskeysByUser($userId)
			: $this->credentialRepository->find2FAKeysByUser($userId);
		$excludeCredentials = array_values(array_map(
			fn (ActiveRow $row) => $this->credentialRepository->toCredentialSource($row)->getPublicKeyCredentialDescriptor(),
			$existingCredentials,
		));

		$authenticatorSelection = $isPasskey
			? AuthenticatorSelectionCriteria::create(
				residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
				userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
			)
			: AuthenticatorSelectionCriteria::create(
				userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
			);

		return PublicKeyCredentialCreationOptions::create(
			rp: $rp,
			user: $user,
			challenge: $challenge,
			pubKeyCredParams: [
				PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
				PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
			],
			authenticatorSelection: $authenticatorSelection,
			attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
			excludeCredentials: $excludeCredentials,
			timeout: 60_000,
		);
	}

	/**
	 * Serialize creation options to JSON for the browser.
	 */
	public function serializeOptions(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): string
	{
		return $this->getSerializer()->serialize($options, 'json');
	}

	/**
	 * Process registration response from browser.
	 *
	 * @return ActiveRow the created credential
	 */
	public function processRegistration(
		int $userId,
		string $responseJson,
		string $storedOptionsJson,
		string $name,
		bool $isPasskey,
		string $host,
	): ActiveRow {
		$serializer = $this->getSerializer();

		/** @var PublicKeyCredential $publicKeyCredential */
		$publicKeyCredential = $serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');

		if (!$publicKeyCredential->response instanceof \Webauthn\AuthenticatorAttestationResponse) {
			throw new \RuntimeException('Invalid response type');
		}

		/** @var PublicKeyCredentialCreationOptions $storedOptions */
		$storedOptions = $serializer->deserialize($storedOptionsJson, PublicKeyCredentialCreationOptions::class, 'json');

		$ceremonyStepManager = $this->ceremonyFactory->creationCeremony();
		$validator = AuthenticatorAttestationResponseValidator::create($ceremonyStepManager);

		$credentialSource = $validator->check(
			$publicKeyCredential->response,
			$storedOptions,
			$host,
		);

		return $this->credentialRepository->createFromRegistration(
			$userId,
			$credentialSource,
			$name,
			$isPasskey,
		);
	}

	/**
	 * Create assertion options for passkey login (discoverable credentials, no specific user).
	 */
	public function createPasskeyAssertionOptions(): PublicKeyCredentialRequestOptions
	{
		return PublicKeyCredentialRequestOptions::create(
			challenge: random_bytes(32),
			rpId: $this->rpId,
			allowCredentials: [],
			userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
			timeout: 60_000,
		);
	}

	/**
	 * Create assertion options for 2FA (user known, limited to user's security keys).
	 */
	public function create2FAAssertionOptions(int $userId): PublicKeyCredentialRequestOptions
	{
		$keys = $this->credentialRepository->find2FAKeysByUser($userId);
		$allowCredentials = array_values(array_map(
			fn (ActiveRow $row) => $this->credentialRepository->toCredentialSource($row)->getPublicKeyCredentialDescriptor(),
			$keys,
		));

		return PublicKeyCredentialRequestOptions::create(
			challenge: random_bytes(32),
			rpId: $this->rpId,
			allowCredentials: $allowCredentials,
			userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
			timeout: 60_000,
		);
	}

	/**
	 * Verify assertion response from browser (for both 2FA and passkey login).
	 *
	 * @return array{credential: ActiveRow, source: PublicKeyCredentialSource} verified credential
	 */
	public function verifyAssertion(
		string $responseJson,
		string $storedOptionsJson,
		string $host,
		?int $userId = null,
	): array {
		$serializer = $this->getSerializer();

		/** @var PublicKeyCredential $publicKeyCredential */
		$publicKeyCredential = $serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');

		if (!$publicKeyCredential->response instanceof \Webauthn\AuthenticatorAssertionResponse) {
			throw new \RuntimeException('Invalid response type');
		}

		// Find stored credential by credential ID
		$credentialIdBase64 = base64_encode($publicKeyCredential->rawId);
		$credentialRow = $this->credentialRepository->findByCredentialId($credentialIdBase64);
		if ($credentialRow === null) {
			throw new \RuntimeException('Unknown credential');
		}

		// If userId is specified (2FA mode), verify credential belongs to the user
		if ($userId !== null && $credentialRow->user_id !== $userId) {
			throw new \RuntimeException('Credential does not belong to user');
		}

		$credentialSource = $this->credentialRepository->toCredentialSource($credentialRow);

		/** @var PublicKeyCredentialRequestOptions $storedOptions */
		$storedOptions = $serializer->deserialize($storedOptionsJson, PublicKeyCredentialRequestOptions::class, 'json');

		$ceremonyStepManager = $this->ceremonyFactory->requestCeremony();
		$validator = AuthenticatorAssertionResponseValidator::create($ceremonyStepManager);

		$updatedSource = $validator->check(
			$credentialSource,
			$publicKeyCredential->response,
			$storedOptions,
			$host,
			$credentialSource->userHandle,
		);

		// Update counter
		$this->credentialRepository->updateCounter($credentialRow->id, $updatedSource->counter);

		return ['credential' => $credentialRow, 'source' => $updatedSource];
	}

	/**
	 * @return \Symfony\Component\Serializer\SerializerInterface
	 */
	private function getSerializer(): \Symfony\Component\Serializer\SerializerInterface
	{
		$attestationManager = new \Webauthn\AttestationStatement\AttestationStatementSupportManager();
		$attestationManager->add(new \Webauthn\AttestationStatement\NoneAttestationStatementSupport());

		$factory = new \Webauthn\Denormalizer\WebauthnSerializerFactory($attestationManager);

		return $factory->create();
	}
}
