<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette\Application\BadRequestException;
use Nette\Application\Request;


final class Error4xxPresenter extends BasePresenter
{
	public function startup(): void
	{
		parent::startup();

		if (!$this->getRequest()->isMethod(Request::FORWARD)) {
			$this->error();
		}
	}


	public function renderDefault(BadRequestException $exception): void
	{
		$code = $exception->getCode();
		$file = __DIR__ . "/templates/Error/$code.latte";
		$this->template->setFile(is_file($file) ? $file : __DIR__ . '/templates/Error/4xx.latte');
		$this->template->code = $code;
	}
}
