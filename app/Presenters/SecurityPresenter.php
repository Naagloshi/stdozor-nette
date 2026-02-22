<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Model\Repository\UserRepository;
use App\Model\Repository\WebAuthnCredentialRepository;
use App\Model\Service\BackupCodeService;
use App\Model\Service\TotpSetupService;
use App\Model\Service\TrustedDeviceService;
use App\Model\Service\WebAuthnService;
use Contributte\Translation\Translator;
use Nette\Application\Responses\JsonResponse;
use Nette\Security\Passwords;

final class SecurityPresenter extends BasePresenter
{
	public function __construct(
		private UserRepository $userRepository,
		private WebAuthnCredentialRepository $webAuthnRepository,
		private TotpSetupService $totpService,
		private BackupCodeService $backupCodeService,
		private WebAuthnService $webAuthnService,
		private TrustedDeviceService $trustedDeviceService,
		private Passwords $passwords,
		private Translator $translator,
	) {}

	// ---- Security Overview ----

	public function actionDefault(): void
	{
		$this->requireLogin();
	}

	public function renderDefault(): void
	{
		$userId = $this->getUser()->getId();
		$userRow = $this->userRepository->findById($userId);
		$keys2fa = $this->webAuthnRepository->find2FAKeysByUser($userId);
		$passkeys = $this->webAuthnRepository->findPasskeysByUser($userId);

		$this->template->userRow = $userRow;
		$this->template->keys2fa = $keys2fa;
		$this->template->passkeys = $passkeys;
		$this->template->backupCodesCount = count(json_decode($userRow->backup_codes, true) ?: []);
		$this->template->totpEnabled = $userRow->totp_secret !== null && $userRow->totp_secret !== '';
	}

	// ---- TOTP Setup ----

	public function actionTotpSetup(): void
	{
		$this->requireLogin();

		$userId = $this->getUser()->getId();
		$userRow = $this->userRepository->findById($userId);

		if ($userRow->totp_secret !== null && $userRow->totp_secret !== '') {
			$this->flashMessage($this->translator->translate('messages.security.totp.already_enabled'), 'info');
			$this->redirect('Security:default');
		}

		$session = $this->getSession('totp_setup');
		$secret = $session->secret ?? null;
		if ($secret === null || !preg_match('/^[A-Z2-7]+=*$/i', $secret)) {
			$secret = $this->totpService->generateSecret();
			$session->secret = $secret;
		}

		$email = $this->getUser()->getIdentity()->getData()['email'];
		$qrCodeDataUri = $this->totpService->getQrCodeDataUri($email, $secret);

		$this->template->qrCodeDataUri = $qrCodeDataUri;
		$this->template->secret = $secret;
	}

	/**
	 * Signal: enable TOTP after verification.
	 */
	public function handleTotpEnable(): void
	{
		$this->requireLogin();

		$session = $this->getSession('totp_setup');
		$secret = $session->secret ?? null;
		$code = $this->getHttpRequest()->getPost('code') ?? '';

		if ($secret === null || $code === '') {
			$this->flashMessage($this->translator->translate('messages.security.totp.invalid_code'), 'error');
			$this->redirect('Security:totpSetup');
		}

		if (!$this->totpService->verifyCode($secret, $code)) {
			$this->flashMessage($this->translator->translate('messages.security.totp.invalid_code'), 'error');
			$this->redirect('Security:totpSetup');
		}

		$userId = $this->getUser()->getId();
		$this->userRepository->updateTotpSecret($userId, $secret);
		unset($session->secret);

		// Generate backup codes if user doesn't have them
		$userRow = $this->userRepository->findById($userId);
		$existingCodes = json_decode($userRow->backup_codes, true) ?: [];
		if (count($existingCodes) === 0) {
			$result = $this->backupCodeService->generateCodes();
			$this->userRepository->updateBackupCodes($userId, $result['hashed']);
			$this->getSession('backup_codes')->codes = $result['plain'];
			$this->getSession('backup_codes')->generatedAt = time();
		}

		$this->flashMessage($this->translator->translate('messages.security.totp.enable_success'), 'success');

		if (isset($this->getSession('backup_codes')->codes)) {
			$this->redirect('Security:backupCodes');
		}

		$this->redirect('Security:default');
	}

	/**
	 * Signal: disable TOTP (requires password).
	 */
	public function handleTotpDisable(): void
	{
		$this->requireLogin();

		$password = $this->getHttpRequest()->getPost('password') ?? '';
		$userId = $this->getUser()->getId();
		$userRow = $this->userRepository->findById($userId);

		if (!$this->passwords->verify($password, $userRow->password)) {
			$this->flashMessage($this->translator->translate('messages.profile.flash.password_current_incorrect'), 'error');
			$this->redirect('Security:default');
		}

		$this->userRepository->updateTotpSecret($userId, null);
		$this->flashMessage($this->translator->translate('messages.security.totp.disable_success'), 'success');
		$this->redirect('Security:default');
	}

	// ---- WebAuthn Registration ----

	public function actionWebauthnRegister(string $type = '2fa'): void
	{
		$this->requireLogin();

		if (!in_array($type, ['2fa', 'passkey'], true)) {
			$this->error('Invalid type');
		}

		$this->template->type = $type;
		$this->template->isPasskey = $type === 'passkey';
	}

	/**
	 * Signal: return WebAuthn registration options as JSON.
	 */
	public function handleWebauthnRegisterOptions(): void
	{
		$this->requireLogin();

		$body = json_decode(file_get_contents('php://input'), true) ?: [];
		$isPasskey = (bool) ($body['isPasskey'] ?? false);
		$userId = $this->getUser()->getId();
		$identity = $this->getUser()->getIdentity();
		$email = $identity->getData()['email'];
		$displayName = $identity->getData()['displayName'];

		$options = $this->webAuthnService->createRegistrationOptions($userId, $email, $displayName, $isPasskey);
		$json = $this->webAuthnService->serializeOptions($options);

		$session = $this->getSession('webauthn_register');
		$session->options = $json;
		$session->isPasskey = $isPasskey;

		$this->sendResponse(new JsonResponse(json_decode($json, true)));
	}

	/**
	 * Signal: complete WebAuthn registration.
	 */
	public function handleWebauthnRegisterComplete(): void
	{
		$this->requireLogin();

		$session = $this->getSession('webauthn_register');
		$storedOptions = $session->options ?? null;
		$storedIsPasskey = $session->isPasskey ?? false;

		if ($storedOptions === null) {
			$this->getHttpResponse()->setCode(400);
			$this->sendResponse(new JsonResponse(['error' => 'No pending registration']));
		}
		unset($session->options, $session->isPasskey);

		$body = file_get_contents('php://input');
		$data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
		$name = trim((string) ($data['name'] ?? 'Key'));
		if ($name === '' || mb_strlen($name) > 100) {
			$this->getHttpResponse()->setCode(400);
			$this->sendResponse(new JsonResponse(['error' => 'Invalid key name']));
		}

		$credentialJson = json_encode($data['credential'], \JSON_THROW_ON_ERROR);

		try {
			$this->webAuthnService->processRegistration(
				$this->getUser()->getId(),
				$credentialJson,
				$storedOptions,
				$name,
				$storedIsPasskey,
				$this->getHttpRequest()->getUrl()->getHost(),
			);

			$this->sendResponse(new JsonResponse(['success' => true]));
		} catch (\Nette\Application\AbortException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->getHttpResponse()->setCode(400);
			$this->sendResponse(new JsonResponse(['error' => 'Registration failed: ' . $e->getMessage()]));
		}
	}

	/**
	 * Signal: delete a WebAuthn credential.
	 */
	public function handleWebauthnDelete(int $id): void
	{
		$this->requireLogin();

		$credential = $this->webAuthnRepository->findById($id);
		if ($credential === null || $credential->user_id !== $this->getUser()->getId()) {
			$this->flashMessage($this->translator->translate('messages.flash.error.not_found'), 'error');
			$this->redirect('Security:default');
		}

		$this->webAuthnRepository->delete($id);
		$this->flashMessage($this->translator->translate('messages.security.webauthn.deleted_success'), 'success');
		$this->redirect('Security:default');
	}

	// ---- Backup Codes ----

	public function actionBackupCodes(): void
	{
		$this->requireLogin();

		$session = $this->getSession('backup_codes');
		$codes = null;

		if (isset($session->codes, $session->generatedAt)) {
			if (time() - $session->generatedAt < 300) { // 5 minutes
				$codes = $session->codes;
			}
			unset($session->codes, $session->generatedAt);
		}

		$userId = $this->getUser()->getId();
		$userRow = $this->userRepository->findById($userId);
		$backupCodesCount = count(json_decode($userRow->backup_codes, true) ?: []);

		$this->template->codes = $codes;
		$this->template->backupCodesCount = $backupCodesCount;
	}

	/**
	 * Signal: regenerate backup codes (requires password).
	 */
	public function handleBackupCodesGenerate(): void
	{
		$this->requireLogin();

		$userId = $this->getUser()->getId();
		$userRow = $this->userRepository->findById($userId);

		if (!$this->userRepository->has2FAEnabled($userRow)) {
			$this->flashMessage($this->translator->translate('messages.security.backup_codes.no_2fa_active'), 'error');
			$this->redirect('Security:default');
		}

		$password = $this->getHttpRequest()->getPost('password') ?? '';
		if (!$this->passwords->verify($password, $userRow->password)) {
			$this->flashMessage($this->translator->translate('messages.profile.flash.password_current_incorrect'), 'error');
			$this->redirect('Security:default');
		}

		$result = $this->backupCodeService->generateCodes();
		$this->userRepository->updateBackupCodes($userId, $result['hashed']);

		$session = $this->getSession('backup_codes');
		$session->codes = $result['plain'];
		$session->generatedAt = time();

		$this->flashMessage($this->translator->translate('messages.security.backup_codes.generated_success'), 'success');
		$this->redirect('Security:backupCodes');
	}

	// ---- Trusted Devices ----

	/**
	 * Signal: revoke all trusted devices.
	 */
	public function handleTrustedDevicesRevoke(): void
	{
		$this->requireLogin();

		$userId = $this->getUser()->getId();
		$this->userRepository->incrementTrustedVersion($userId);
		$this->trustedDeviceService->removeCookie();

		$this->flashMessage($this->translator->translate('messages.security.trusted_device.revoked_success'), 'success');
		$this->redirect('Security:default');
	}
}
