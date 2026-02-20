<?php

declare(strict_types=1);

namespace App\Router;

use Nette\Application\Routers\RouteList;


final class RouterFactory
{
	public static function createRouter(): RouteList
	{
		$router = new RouteList;

		// Auth
		$router->addRoute('prihlaseni', 'Sign:in');
		$router->addRoute('registrace', 'Sign:up');
		$router->addRoute('odhlaseni', 'Sign:out');
		$router->addRoute('zapomenute-heslo', 'Sign:forgotPassword');
		$router->addRoute('kontrola-emailu', 'Sign:checkEmail');
		$router->addRoute('reset-hesla[/<token>]', 'Sign:resetPassword');
		$router->addRoute('overeni-emailu/<token>', 'Sign:verifyEmail');
		$router->addRoute('znovu-overeni', 'Sign:resendVerification');

		// Fallback
		$router->addRoute('<presenter>/<action>[/<id>]', 'Homepage:default');

		return $router;
	}
}
