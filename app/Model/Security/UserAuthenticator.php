<?php

declare(strict_types=1);

namespace App\Model\Security;

use App\Model\Repository\UserRepository;
use Contributte\Translation\Translator;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;


final class UserAuthenticator implements Authenticator
{
	public function __construct(
		private UserRepository $userRepository,
		private Passwords $passwords,
		private Translator $translator,
	) {
	}


	public function authenticate(string $user, string $password): IIdentity
	{
		$row = $this->userRepository->findByEmail($user);

		if (!$row) {
			throw new AuthenticationException(
				$this->translator->translate('messages.security.error.invalid_credentials'),
			);
		}

		if (!$this->passwords->verify($password, $row->password)) {
			throw new AuthenticationException(
				$this->translator->translate('messages.security.error.invalid_credentials'),
			);
		}

		if (!(bool) $row->is_verified) {
			throw new AuthenticationException(
				$this->translator->translate('messages.security.user_checker.not_verified'),
			);
		}

		// Rehash password if needed (algorithm upgrade)
		if ($this->passwords->needsRehash($row->password)) {
			$this->userRepository->updatePassword($row->id, $this->passwords->hash($password));
		}

		$profile = $this->userRepository->getProfile($row->id);
		$displayName = trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? ''));
		if ($displayName === '') {
			$displayName = $row->email;
		}

		return new SimpleIdentity(
			$row->id,
			json_decode($row->roles, true),
			[
				'email' => $row->email,
				'displayName' => $displayName,
			],
		);
	}
}
