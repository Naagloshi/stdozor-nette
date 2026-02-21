<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Model\Repository\UserRepository;
use App\Model\Security\PwnedPasswordChecker;
use Contributte\Translation\Translator;
use Nette\Application\UI\Form;
use Nette\Security\Passwords;


final class ProfilePresenter extends BasePresenter
{
	public function __construct(
		private UserRepository $userRepository,
		private Passwords $passwords,
		private PwnedPasswordChecker $pwnedChecker,
		private Translator $translator,
	) {
	}


	public function actionDefault(): void
	{
		$this->requireLogin();

		$userId = $this->getUser()->getId();
		$this->template->userRow = $this->userRepository->findById($userId);
		$this->template->profile = $this->userRepository->getProfile($userId);
	}


	public function actionEdit(): void
	{
		$this->requireLogin();

		$userId = $this->getUser()->getId();
		$user = $this->userRepository->findById($userId);
		$profile = $this->userRepository->getProfile($userId);

		$this->template->userRow = $user;
		$this->template->profile = $profile;

		$this['editProfileForm']->setDefaults([
			'email' => $user->email,
			'firstName' => $profile?->first_name,
			'lastName' => $profile?->last_name,
			'phone' => $profile?->phone,
			'bio' => $profile?->bio,
		]);
	}


	protected function createComponentEditProfileForm(): Form
	{
		$form = new Form;

		$form->addEmail('email', $this->translator->translate('messages.entity.user.email'))
			->setDisabled();

		$form->addText('firstName', $this->translator->translate('messages.entity.profile.first_name'))
			->setRequired(false)
			->addRule($form::MaxLength, null, 100);

		$form->addText('lastName', $this->translator->translate('messages.entity.profile.last_name'))
			->setRequired(false)
			->addRule($form::MaxLength, null, 100);

		$form->addText('phone', $this->translator->translate('messages.entity.profile.phone'))
			->setRequired(false)
			->addRule($form::MaxLength, null, 20);

		$form->addTextArea('bio', $this->translator->translate('messages.entity.profile.bio'))
			->setRequired(false)
			->setHtmlAttribute('rows', 5);

		$form->addPassword('currentPassword', $this->translator->translate('messages.profile.form.current_password'))
			->setRequired(false)
			->setHtmlAttribute('autocomplete', 'current-password');

		$form->addPassword('newPassword', $this->translator->translate('messages.profile.form.new_password'))
			->setRequired(false)
			->setHtmlAttribute('autocomplete', 'new-password');
		$form['newPassword']
			->addCondition($form::Filled)
				->addRule($form::MinLength, $this->translator->translate('messages.validators.password.min_length'), 16);

		$form->addPassword('newPasswordConfirm', $this->translator->translate('messages.profile.form.new_password_confirm'))
			->setRequired(false)
			->setHtmlAttribute('autocomplete', 'new-password');
		$form['newPasswordConfirm']
			->addConditionOn($form['newPassword'], $form::Filled)
				->setRequired($this->translator->translate('messages.profile.form.new_password_confirm'))
				->addRule($form::Equal, 'Hesla se neshodují.', $form['newPassword']);

		$form->addSubmit('send', $this->translator->translate('messages.action.save'));
		$form->addProtection();

		$form->onSuccess[] = $this->editProfileFormSucceeded(...);
		return $form;
	}


	private function editProfileFormSucceeded(Form $form, \stdClass $data): void
	{
		$userId = $this->getUser()->getId();
		$passwordChanged = false;

		// Handle optional password change
		if ($data->newPassword) {
			if (!$data->currentPassword) {
				$this->flashMessage(
					$this->translator->translate('messages.profile.flash.password_current_required'),
					'error',
				);
				$this->redirect('this');
			}

			$user = $this->userRepository->findById($userId);
			if (!$this->passwords->verify($data->currentPassword, $user->password)) {
				$this->flashMessage(
					$this->translator->translate('messages.profile.flash.password_current_incorrect'),
					'error',
				);
				$this->redirect('this');
			}

			if ($this->pwnedChecker->isCompromised($data->newPassword)) {
				$form['newPassword']->addError(
					$this->translator->translate('messages.validators.password.compromised'),
				);
				return;
			}

			$this->userRepository->updatePassword($userId, $this->passwords->hash($data->newPassword));
			$passwordChanged = true;
		}

		// Update profile
		$this->userRepository->updateProfile($userId, [
			'first_name' => $data->firstName ?: null,
			'last_name' => $data->lastName ?: null,
			'phone' => $data->phone ?: null,
			'bio' => $data->bio ?: null,
		]);

		// Update displayName in session Identity
		$identity = $this->getUser()->getIdentity();
		if ($identity !== null) {
			$displayName = trim(($data->firstName ?? '') . ' ' . ($data->lastName ?? ''));
			$identity->displayName = $displayName ?: $identity->email;
		}

		$flashKey = $passwordChanged
			? 'messages.profile.flash.updated_with_password'
			: 'messages.profile.flash.updated';
		$this->flashMessage($this->translator->translate($flashKey), 'success');
		$this->redirect('Profile:default');
	}
}
