<?php

declare(strict_types=1);

namespace App\Presenters;

final class HomepagePresenter extends BasePresenter
{
	public function actionDefault(): void
	{
		$this->flashMessage('Aplikace běží správně!', 'success');
	}
}
