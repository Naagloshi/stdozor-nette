<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Model\Repository\UserRepository;
use App\Model\Security\EmailVerificationService;
use App\Model\Security\MailService;
use App\Model\Security\PasswordResetService;
use App\Model\Security\PwnedPasswordChecker;
use Contributte\Translation\Translator;
use Nette\Application\UI\Form;
use Nette\Security\AuthenticationException;
use Nette\Security\Passwords;

final class SignPresenter extends BasePresenter
{
	public function __construct(
		private UserRepository $userRepository,
		private Passwords $passwords,
		private EmailVerificationService $emailVerification,
		private PasswordResetService $passwordReset,
		private PwnedPasswordChecker $pwnedChecker,
		private MailService $mailService,
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

		// Check for pending invitation token
		$invitationToken = $this->getSession('invitation')->token ?? null;
		if ($invitationToken) {
			unset($this->getSession('invitation')->token);
			$this->redirect('Member:accept', ['token' => $invitationToken]);
		}

		// Restore backlink or go to projects
		$backlink = $this->getParameter('backlink');
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
}
