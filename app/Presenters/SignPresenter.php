<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Model\Repository\UserRepository;
use App\Model\Repository\WebAuthnCredentialRepository;
use App\Model\Security\EmailVerificationService;
use App\Model\Security\MailService;
use App\Model\Security\PasswordResetService;
use App\Model\Security\PwnedPasswordChecker;
use App\Model\Service\BackupCodeService;
use App\Model\Service\TotpSetupService;
use App\Model\Service\TrustedDeviceService;
use App\Model\Service\WebAuthnService;
use Contributte\Translation\Translator;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\UI\Form;
use Nette\Security\AuthenticationException;
use Nette\Security\Passwords;

final class SignPresenter extends BasePresenter
{
	public function __construct(
		private UserRepository $userRepository,
		private WebAuthnCredentialRepository $webAuthnRepository,
		private Passwords $passwords,
		private EmailVerificationService $emailVerification,
		private PasswordResetService $passwordReset,
		private PwnedPasswordChecker $pwnedChecker,
		private MailService $mailService,
		private TotpSetupService $totpService,
		private BackupCodeService $backupCodeService,
		private WebAuthnService $webAuthnService,
		private TrustedDeviceService $trustedDeviceService,
		private Translator $translator,
	) {}

	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->setLayout(__DIR__ . '/templates/@layout-security.latte');
	}

	// ---- Login ----

	public function actionIn(string $backlink = ''): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect('Homepage:default');
		}
	}

	protected function createComponentLoginForm(): Form
	{
		$form = new Form();
		$form->addEmail('email', $this->translator->translate('messages.security.login.email'))
			->setRequired($this->translator->translate('messages.security.registration.email_required'))
			->setHtmlAttribute('autofocus');

		$form->addPassword('password', $this->translator->translate('messages.security.login.password'))
			->setRequired($this->translator->translate('messages.validators.password.min_length'));

		$form->addSubmit('send', $this->translator->translate('messages.security.login.submit'));
		$form->addProtection();

		$form->onSuccess[] = $this->loginFormSucceeded(...);

		return $form;
	}

	private function loginFormSucceeded(Form $form, \stdClass $data): void
	{
		try {
			$this->getUser()->login($data->email, $data->password);
		} catch (AuthenticationException $e) {
			$this->flashMessage($e->getMessage(), 'error');
			$this->redirect('this');
		}

		$userId = $this->getUser()->getId();
		$userRow = $this->userRepository->findById($userId);

		// Check if user has 2FA enabled and device is not trusted
		if ($this->userRepository->has2FAEnabled($userRow)
			&& !$this->trustedDeviceService->isTrusted($userId, $userRow->trusted_version)
		) {
			// Store identity in session and logout (without destroying session)
			$section = $this->getSession('2fa');
			$section->identity = $this->getUser()->getIdentity();
			$section->userId = $userId;
			$section->backlink = $this->getParameter('backlink');
			$section->setExpiration('5 minutes');
			$this->getUser()->logout(); // Without session destroy
			$this->redirect('Sign:twoFactor');
		}

		$this->completeLogin();
	}

	/**
	 * Finish login: restore backlink or redirect to projects.
	 */
	private function completeLogin(): void
	{
		// Check for pending invitation token
		$invitationToken = $this->getSession('invitation')->token ?? null;
		if ($invitationToken) {
			unset($this->getSession('invitation')->token);
			$this->redirect('Member:accept', ['token' => $invitationToken]);
		}

		// Restore backlink from session (stored during 2FA flow) or from parameter
		$section = $this->getSession('2fa');
		$backlink = $section->backlink ?? $this->getParameter('backlink');
		unset($section->backlink);

		if ($backlink) {
			$this->restoreRequest($backlink);
		}

		$this->redirect('Project:default');
	}

	// ---- Registration ----

	public function actionUp(): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect('Homepage:default');
		}
	}

	protected function createComponentRegistrationForm(): Form
	{
		$form = new Form();
		$form->addEmail('email', $this->translator->translate('messages.security.registration.email'))
			->setRequired($this->translator->translate('messages.security.registration.email_required'))
			->setHtmlAttribute('autofocus');

		$form->addPassword('plainPassword', $this->translator->translate('messages.security.registration.password'))
			->setRequired($this->translator->translate('messages.validators.password.min_length'))
			->addRule($form::MinLength, $this->translator->translate('messages.validators.password.min_length'), 16)
			->setHtmlAttribute('placeholder', '')
			->setOption('description', $this->translator->translate('messages.validators.password.help'));

		$form->addCheckbox('agreeTerms', $this->translator->translate('messages.security.registration.agree_terms'))
			->setRequired();

		$form->addSubmit('send', $this->translator->translate('messages.security.registration.submit'));
		$form->addProtection();

		$form->onSuccess[] = $this->registrationFormSucceeded(...);

		return $form;
	}

	private function registrationFormSucceeded(Form $form, \stdClass $data): void
	{
		$email = $data->email;

		// HIBP check (non-blocking if API unavailable)
		if ($this->pwnedChecker->isCompromised($data->plainPassword)) {
			/** @var \Nette\Forms\Controls\BaseControl $plainPasswordControl */
			$plainPasswordControl = $form['plainPassword'];
			$plainPasswordControl->addError(
				$this->translator->translate('messages.validators.password.compromised'),
			);

			return;
		}

		// Check for existing user with this email
		$existingUser = $this->userRepository->findByEmail($email);
		if ($existingUser) {
			if ((bool) $existingUser->is_verified) {
				/** @var \Nette\Forms\Controls\BaseControl $emailControl */
				$emailControl = $form['email'];
				$emailControl->addError(
					$this->translator->translate('messages.security.registration.email_already_exists'),
				);

				return;
			}

			// Unverified account — delete and recreate
			$this->userRepository->deleteUnverified($existingUser->id);
		}

		// Create user
		$hashedPassword = $this->passwords->hash($data->plainPassword);
		$user = $this->userRepository->insert([
			'email' => $email,
			'password' => $hashedPassword,
			'roles' => '["ROLE_USER"]',
			'is_verified' => 0,
			'created_at' => new \DateTime(),
			'backup_codes' => '[]',
			'trusted_version' => 0,
		]);

		// Send verification email
		$token = $this->emailVerification->createToken($user->id);
		$verificationUrl = $this->link('//Sign:verifyEmail', ['token' => $token]);

		$this->mailService->send(
			$email,
			$this->translator->translate('messages.security.email_verification.subject'),
			__DIR__ . '/templates/emails/verification.latte',
			['verificationUrl' => $verificationUrl],
		);

		$this->flashMessage(
			$this->translator->translate('messages.security.registration.check_email'),
			'success',
		);
		$this->redirect('Sign:in');
	}

	// ---- Logout ----

	public function actionOut(): void
	{
		$this->getUser()->logout(true);
		$this->flashMessage(
			$this->translator->translate('messages.security.logout.success'),
			'success',
		);
		$this->redirect('Homepage:default');
	}

	// ---- Email Verification ----

	public function actionVerifyEmail(string $token): void
	{
		try {
			$this->emailVerification->verify($token);
			$this->flashMessage(
				$this->translator->translate('messages.security.email_verification.success'),
				'success',
			);
			$this->redirect('Sign:in');
		} catch (\RuntimeException $e) {
			$this->flashMessage(
				$this->translator->translate('messages.' . $e->getMessage()),
				'error',
			);
			$this->redirect('Sign:up');
		}
	}

	// ---- Forgot Password ----

	public function actionForgotPassword(): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect('Homepage:default');
		}
	}

	protected function createComponentForgotPasswordForm(): Form
	{
		$form = new Form();
		$form->addEmail('email', $this->translator->translate('messages.security.reset_password.request.email'))
			->setRequired($this->translator->translate('messages.security.registration.email_required'))
			->setHtmlAttribute('autofocus');

		$form->addSubmit('send', $this->translator->translate('messages.security.reset_password.request.submit'));
		$form->addProtection();

		$form->onSuccess[] = $this->forgotPasswordFormSucceeded(...);

		return $form;
	}

	private function forgotPasswordFormSucceeded(Form $form, \stdClass $data): void
	{
		$user = $this->userRepository->findByEmail($data->email);

		if ($user) {
			$token = $this->passwordReset->createResetToken($user->id);
			$resetUrl = $this->link('//Sign:resetPassword', ['token' => $token]);

			$this->mailService->send(
				$user->email,
				$this->translator->translate('messages.security.reset_password.email.subject'),
				__DIR__ . '/templates/emails/resetPassword.latte',
				['resetUrl' => $resetUrl],
			);
		}

		// Always redirect — never reveal if email exists
		$this->redirect('Sign:checkEmail');
	}

	// ---- Check Email (info page after reset request) ----

	public function actionCheckEmail(): void {}

	// ---- Reset Password ----

	public function actionResetPassword(?string $token = null): void
	{
		if ($token !== null) {
			// Store token in session and remove from URL (prevent leak to 3rd-party JS/referrer)
			$this->getSession('resetPassword')->token = $token;
			$this->redirect('Sign:resetPassword');
		}

		$sessionToken = $this->getSession('resetPassword')->token ?? null;
		if ($sessionToken === null) {
			$this->flashMessage(
				$this->translator->translate('messages.security.email_verification.invalid_link'),
				'error',
			);
			$this->redirect('Sign:forgotPassword');
		}

		// Validate token
		$user = $this->passwordReset->validateTokenAndFetchUser($sessionToken);
		if (!$user) {
			unset($this->getSession('resetPassword')->token);
			$this->flashMessage(
				$this->translator->translate('messages.security.email_verification.invalid_link'),
				'error',
			);
			$this->redirect('Sign:forgotPassword');
		}
	}

	protected function createComponentResetPasswordForm(): Form
	{
		$form = new Form();
		$form->addPassword('plainPassword', $this->translator->translate('messages.security.reset_password.reset.new_password'))
			->setRequired($this->translator->translate('messages.validators.password.min_length'))
			->addRule($form::MinLength, $this->translator->translate('messages.validators.password.min_length'), 16)
			->setHtmlAttribute('autofocus');

		$form->addPassword('confirmPassword', $this->translator->translate('messages.security.reset_password.reset.repeat_password'))
			->setRequired($this->translator->translate('messages.validators.password.min_length'))
			->addRule($form::Equal, $this->translator->translate('messages.validators.password.min_length'), $form['plainPassword']);

		$form->addSubmit('send', $this->translator->translate('messages.security.reset_password.reset.submit'));
		$form->addProtection();

		$form->onSuccess[] = $this->resetPasswordFormSucceeded(...);

		return $form;
	}

	private function resetPasswordFormSucceeded(Form $form, \stdClass $data): void
	{
		$sessionToken = $this->getSession('resetPassword')->token;
		$user = $this->passwordReset->validateTokenAndFetchUser($sessionToken);

		if (!$user) {
			$this->flashMessage(
				$this->translator->translate('messages.security.email_verification.invalid_link'),
				'error',
			);
			$this->redirect('Sign:forgotPassword');
		}

		// HIBP check
		if ($this->pwnedChecker->isCompromised($data->plainPassword)) {
			/** @var \Nette\Forms\Controls\BaseControl $plainPasswordControl */
			$plainPasswordControl = $form['plainPassword'];
			$plainPasswordControl->addError(
				$this->translator->translate('messages.validators.password.compromised'),
			);

			return;
		}

		// Update password
		$hashedPassword = $this->passwords->hash($data->plainPassword);
		$this->userRepository->updatePassword($user->id, $hashedPassword);

		// Clean up
		$this->passwordReset->removeResetRequest($sessionToken);
		unset($this->getSession('resetPassword')->token);

		$this->flashMessage(
			$this->translator->translate('messages.security.logout.success'),
			'success',
		);
		$this->redirect('Sign:in');
	}

	// ---- Resend Verification ----

	public function actionResendVerification(): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect('Homepage:default');
		}
	}

	protected function createComponentResendVerificationForm(): Form
	{
		$form = new Form();
		$form->addEmail('email', $this->translator->translate('messages.security.registration.email'))
			->setRequired($this->translator->translate('messages.security.registration.email_required'))
			->setHtmlAttribute('autofocus');

		$form->addSubmit('send', $this->translator->translate('messages.security.registration.resend_submit'));
		$form->addProtection();

		$form->onSuccess[] = $this->resendVerificationFormSucceeded(...);

		return $form;
	}

	private function resendVerificationFormSucceeded(Form $form, \stdClass $data): void
	{
		$user = $this->userRepository->findByEmail($data->email);

		if ($user && !(bool) $user->is_verified) {
			$token = $this->emailVerification->createToken($user->id);
			$verificationUrl = $this->link('//Sign:verifyEmail', ['token' => $token]);

			$this->mailService->send(
				$user->email,
				$this->translator->translate('messages.security.email_verification.subject'),
				__DIR__ . '/templates/emails/verification.latte',
				['verificationUrl' => $verificationUrl],
			);
		}

		// Always show success — never reveal if email exists
		$this->flashMessage(
			$this->translator->translate('messages.security.registration.check_email'),
			'success',
		);
		$this->redirect('Sign:in');
	}

	// ---- Two-Factor Authentication ----

	public function actionTwoFactor(): void
	{
		$section = $this->getSession('2fa');
		if (!isset($section->userId)) {
			$this->redirect('Sign:in');
		}

		$userId = $section->userId;
		$userRow = $this->userRepository->findById($userId);
		$hasTotpEnabled = $userRow->totp_secret !== null && $userRow->totp_secret !== '';
		$has2FAKeys = count($this->webAuthnRepository->find2FAKeysByUser($userId)) > 0;

		// If user has only WebAuthn keys (no TOTP), go directly to WebAuthn 2FA
		if (!$hasTotpEnabled && $has2FAKeys) {
			$this->redirect('Sign:twoFactorWebauthn');
		}

		$this->template->hasTotpEnabled = $hasTotpEnabled;
		$this->template->has2FAKeys = $has2FAKeys;
	}

	/**
	 * Signal: verify TOTP code or backup code during 2FA.
	 */
	public function handleTwoFactorVerify(): void
	{
		$section = $this->getSession('2fa');
		if (!isset($section->userId)) {
			$this->redirect('Sign:in');
		}

		$code = trim($this->getHttpRequest()->getPost('code') ?? '');
		$trustDevice = (bool) $this->getHttpRequest()->getPost('trusted');
		$userId = $section->userId;
		$userRow = $this->userRepository->findById($userId);

		// Try TOTP first
		if ($userRow->totp_secret !== null && $userRow->totp_secret !== '') {
			if ($this->totpService->verifyCode($userRow->totp_secret, $code)) {
				$this->complete2FA($section, $trustDevice, $userId, $userRow->trusted_version);

				return;
			}
		}

		// Try backup code
		$hashedCodes = json_decode($userRow->backup_codes, true) ?: [];
		$matchIndex = $this->backupCodeService->verifyCode($code, $hashedCodes);
		if ($matchIndex !== false) {
			// Remove used code
			unset($hashedCodes[$matchIndex]);
			$this->userRepository->updateBackupCodes($userId, array_values($hashedCodes));
			$this->complete2FA($section, $trustDevice, $userId, $userRow->trusted_version);

			return;
		}

		$this->flashMessage($this->translator->translate('messages.security.2fa.invalid_code'), 'error');
		$this->redirect('Sign:twoFactor');
	}

	// ---- Two-Factor WebAuthn ----

	public function actionTwoFactorWebauthn(): void
	{
		$section = $this->getSession('2fa');
		if (!isset($section->userId)) {
			$this->redirect('Sign:in');
		}
	}

	/**
	 * Signal: return WebAuthn 2FA assertion options as JSON.
	 */
	public function handleWebauthn2faOptions(): void
	{
		$section = $this->getSession('2fa');
		if (!isset($section->userId)) {
			$this->getHttpResponse()->setCode(403);
			$this->sendResponse(new JsonResponse(['error' => 'No 2FA session']));
		}

		$options = $this->webAuthnService->create2FAAssertionOptions($section->userId);
		$json = $this->webAuthnService->serializeOptions($options);

		$session2fa = $this->getSession('webauthn_2fa');
		$session2fa->options = $json;

		$this->sendResponse(new JsonResponse(json_decode($json, true)));
	}

	/**
	 * Signal: verify WebAuthn 2FA assertion.
	 */
	public function handleWebauthn2faVerify(): void
	{
		$section = $this->getSession('2fa');
		if (!isset($section->userId)) {
			$this->getHttpResponse()->setCode(403);
			$this->sendResponse(new JsonResponse(['error' => 'No 2FA session']));
		}

		$session2fa = $this->getSession('webauthn_2fa');
		$storedOptions = $session2fa->options ?? null;
		if ($storedOptions === null) {
			$this->getHttpResponse()->setCode(400);
			$this->sendResponse(new JsonResponse(['error' => 'No pending assertion']));
		}
		unset($session2fa->options);

		$body = file_get_contents('php://input');
		$trustDevice = (bool) (json_decode($body, true)['trusted'] ?? false);
		$credentialJson = json_encode(json_decode($body, true)['credential'] ?? [], \JSON_THROW_ON_ERROR);

		try {
			$this->webAuthnService->verifyAssertion(
				$credentialJson,
				$storedOptions,
				$this->getHttpRequest()->getUrl()->getHost(),
				$section->userId,
			);

			$userId = $section->userId;
			$userRow = $this->userRepository->findById($userId);
			$identity = $section->identity;
			unset($section->identity, $section->userId);
			$this->getUser()->login($identity);

			if ($trustDevice) {
				$this->trustedDeviceService->createTrustedCookie($userId, $userRow->trusted_version);
			}

			$this->sendResponse(new JsonResponse(['success' => true, 'redirect' => $this->link('Project:default')]));
		} catch (\Nette\Application\AbortException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->getHttpResponse()->setCode(400);
			$this->sendResponse(new JsonResponse(['error' => 'Verification failed: ' . $e->getMessage()]));
		}
	}

	// ---- Passkey Login ----

	/**
	 * Signal: return passkey assertion options as JSON.
	 */
	public function handlePasskeyOptions(): void
	{
		$options = $this->webAuthnService->createPasskeyAssertionOptions();
		$json = $this->webAuthnService->serializeOptions($options);

		$session = $this->getSession('passkey_login');
		$session->options = $json;

		$this->sendResponse(new JsonResponse(json_decode($json, true)));
	}

	/**
	 * Signal: verify passkey assertion and login.
	 */
	public function handlePasskeyLogin(): void
	{
		$session = $this->getSession('passkey_login');
		$storedOptions = $session->options ?? null;
		if ($storedOptions === null) {
			$this->getHttpResponse()->setCode(400);
			$this->sendResponse(new JsonResponse(['error' => 'No pending assertion']));
		}
		unset($session->options);

		$body = file_get_contents('php://input');
		$credentialJson = $body;

		try {
			$result = $this->webAuthnService->verifyAssertion(
				$credentialJson,
				$storedOptions,
				$this->getHttpRequest()->getUrl()->getHost(),
			);

			// Find user by credential's user_id
			$userId = $result['credential']->user_id;
			$userRow = $this->userRepository->findById($userId);

			if (!$userRow || !(bool) $userRow->is_verified) {
				$this->getHttpResponse()->setCode(400);
				$this->sendResponse(new JsonResponse(['error' => 'Invalid user']));
			}

			// Build identity (same as in UserAuthenticator)
			$profile = $this->userRepository->getProfile($userId);
			$displayName = trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? ''));
			if ($displayName === '') {
				$displayName = $userRow->email;
			}

			$identity = new \Nette\Security\SimpleIdentity(
				$userRow->id,
				json_decode($userRow->roles, true),
				['email' => $userRow->email, 'displayName' => $displayName],
			);

			$this->getUser()->login($identity);

			$this->sendResponse(new JsonResponse(['success' => true, 'redirect' => $this->link('Project:default')]));
		} catch (\Nette\Application\AbortException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->getHttpResponse()->setCode(400);
			$this->sendResponse(new JsonResponse(['error' => 'Passkey verification failed: ' . $e->getMessage()]));
		}
	}

	/**
	 * Complete 2FA verification: re-login, handle trusted device, redirect.
	 */
	private function complete2FA(
		\Nette\Http\SessionSection $section,
		bool $trustDevice,
		int $userId,
		int $trustedVersion,
	): void {
		$identity = $section->identity;
		unset($section->identity, $section->userId);
		$this->getUser()->login($identity);

		if ($trustDevice) {
			$this->trustedDeviceService->createTrustedCookie($userId, $trustedVersion);
		}

		$this->completeLogin();
	}
}
