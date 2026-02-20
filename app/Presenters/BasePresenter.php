<?php

declare(strict_types=1);

namespace App\Presenters;

use Contributte\Translation\Translator;
use Nette\Application\UI\Presenter;


abstract class BasePresenter extends Presenter
{
	private Translator $translator;


	public function injectTranslator(Translator $translator): void
	{
		$this->translator = $translator;
	}


	protected function startup(): void
	{
		parent::startup();
		$this->getSession()->start();
		$this->translator->setLocale($this->translator->getDefaultLocale());
	}


	/**
	 * Require the user to be logged in. Redirects to Sign:in with backlink.
	 */
	protected function requireLogin(): void
	{
		if (!$this->getUser()->isLoggedIn()) {
			$this->redirect('Sign:in', ['backlink' => $this->storeRequest()]);
		}
	}
}
