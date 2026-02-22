<?php

declare(strict_types=1);

namespace App\Model\Repository;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;
use Symfony\Component\Uid\Uuid;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

final class WebAuthnCredentialRepository
{
	public function __construct(
		private Explorer $database,
	) {}

	public function getTable(): Selection
	{
		return $this->database->table('user_webauthn_credentials');
	}

	public function findById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}

	/**
	 * @return ActiveRow[]
	 */
	public function findByUser(int $userId): array
	{
		/** @var ActiveRow[] $rows */
		$rows = $this->getTable()
			->where('user_id', $userId)
			->order('created_at DESC')
			->fetchAll();

		return $rows;
	}

	/**
	 * @return ActiveRow[]
	 */
	public function find2FAKeysByUser(int $userId): array
	{
		/** @var ActiveRow[] $rows */
		$rows = $this->getTable()
			->where('user_id', $userId)
			->where('is_passkey', 0)
			->order('created_at DESC')
			->fetchAll();

		return $rows;
	}

	/**
	 * @return ActiveRow[]
	 */
	public function findPasskeysByUser(int $userId): array
	{
		/** @var ActiveRow[] $rows */
		$rows = $this->getTable()
			->where('user_id', $userId)
			->where('is_passkey', 1)
			->order('created_at DESC')
			->fetchAll();

		return $rows;
	}

	/**
	 * Find credential entity by base64-encoded credential ID.
	 */
	public function findByCredentialId(string $base64CredentialId): ?ActiveRow
	{
		return $this->getTable()
			->where('credential_id', $base64CredentialId)
			->fetch() ?: null;
	}

	/**
	 * Find by userHandle (for passkey login — find all credentials for this user handle).
	 *
	 * @return ActiveRow[]
	 */
	public function findByUserHandle(string $userHandle): array
	{
		/** @var ActiveRow[] $rows */
		$rows = $this->getTable()
			->where('user_handle', $userHandle)
			->fetchAll();

		return $rows;
	}

	/**
	 * Check if a user has any 2FA-capable credentials (TOTP or WebAuthn 2FA keys).
	 */
	public function hasWebAuthn2FAKeys(int $userId): bool
	{
		return $this->getTable()
			->where('user_id', $userId)
			->where('is_passkey', 0)
			->count() > 0;
	}

	/**
	 * Check if user has any passkeys registered.
	 */
	public function hasPasskeys(int $userId): bool
	{
		return $this->getTable()
			->where('user_id', $userId)
			->where('is_passkey', 1)
			->count() > 0;
	}

	/**
	 * Convert a DB row to a webauthn-lib PublicKeyCredentialSource.
	 */
	public function toCredentialSource(ActiveRow $row): PublicKeyCredentialSource
	{
		return PublicKeyCredentialSource::create(
			publicKeyCredentialId: base64_decode($row->credential_id),
			type: $row->type,
			transports: json_decode($row->transports, true) ?: [],
			attestationType: $row->attestation_type,
			trustPath: new EmptyTrustPath(),
			aaguid: Uuid::fromString($row->aaguid),
			credentialPublicKey: base64_decode($row->credential_public_key),
			userHandle: $row->user_handle,
			counter: $row->counter,
			backupEligible: $row->backup_eligible !== null ? (bool) $row->backup_eligible : null,
			backupStatus: $row->backup_status !== null ? (bool) $row->backup_status : null,
		);
	}

	/**
	 * Create a DB row from a webauthn-lib PublicKeyCredentialSource after registration.
	 *
	 * @return ActiveRow the created credential row
	 */
	public function createFromRegistration(
		int $userId,
		PublicKeyCredentialSource $source,
		string $name,
		bool $isPasskey,
	): ActiveRow {
		$transports = $source->transports;

		$row = $this->getTable()->insert([
			'user_id' => $userId,
			'name' => $name,
			'credential_id' => base64_encode($source->publicKeyCredentialId),
			'credential_public_key' => base64_encode($source->credentialPublicKey),
			'is_passkey' => $isPasskey ? 1 : 0,
			'user_handle' => $source->userHandle,
			'type' => $source->type,
			'transport' => count($transports) > 0 ? $transports[0] : null,
			'transports' => json_encode($transports),
			'attestation_type' => $source->attestationType,
			'trust_path' => json_encode($source->trustPath),
			'aaguid' => $source->aaguid->toRfc4122(),
			'counter' => $source->counter,
			'backup_eligible' => $source->backupEligible,
			'backup_status' => $source->backupStatus,
			'created_at' => new \DateTime(),
		]);
		assert($row instanceof ActiveRow);

		return $row;
	}

	/**
	 * Update counter and last_used_at after successful authentication.
	 */
	public function updateCounter(int $id, int $counter): void
	{
		$this->getTable()->where('id', $id)->update([
			'counter' => $counter,
			'last_used_at' => new \DateTime(),
		]);
	}

	public function delete(int $id): void
	{
		$this->getTable()->where('id', $id)->delete();
	}
}
