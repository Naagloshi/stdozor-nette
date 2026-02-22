<?php

declare(strict_types=1);

namespace App\Router;

use Nette\Application\Routers\RouteList;

final class RouterFactory
{
	public static function createRouter(): RouteList
	{
		$router = new RouteList();

		// Auth
		$router->addRoute('prihlaseni', 'Sign:in');
		$router->addRoute('registrace', 'Sign:up');
		$router->addRoute('odhlaseni', 'Sign:out');
		$router->addRoute('zapomenute-heslo', 'Sign:forgotPassword');
		$router->addRoute('kontrola-emailu', 'Sign:checkEmail');
		$router->addRoute('reset-hesla[/<token>]', 'Sign:resetPassword');
		$router->addRoute('overeni-emailu/<token>', 'Sign:verifyEmail');
		$router->addRoute('znovu-overeni', 'Sign:resendVerification');

		// Profile
		$router->addRoute('profil', 'Profile:default');
		$router->addRoute('profil/upravit', 'Profile:edit');

		// Security (2FA, WebAuthn, Passkeys)
		$router->addRoute('profil/zabezpeceni', 'Security:default');
		$router->addRoute('profil/zabezpeceni/totp', 'Security:totpSetup');
		$router->addRoute('profil/zabezpeceni/webauthn/<type>', 'Security:webauthnRegister');
		$router->addRoute('profil/zabezpeceni/zalozni-kody', 'Security:backupCodes');

		// 2FA verification (during login)
		$router->addRoute('dvoufaktor', 'Sign:twoFactor');
		$router->addRoute('dvoufaktor/webauthn', 'Sign:twoFactorWebauthn');

		// Projects
		$router->addRoute('projekty', 'Project:default');
		$router->addRoute('projekt/novy', 'Project:create');
		$router->addRoute('projekt/<id [0-9]+>/upravit', 'Project:edit');
		$router->addRoute('projekt/<id [0-9]+>', 'Project:show');

		// Categories
		$router->addRoute('projekt/<projectId [0-9]+>/kategorie/nova[/<parentId [0-9]+>]', 'Category:create');
		$router->addRoute('kategorie/<id [0-9]+>/upravit', 'Category:edit');

		// Items
		$router->addRoute('kategorie/<categoryId [0-9]+>/polozka/nova', 'Item:create');
		$router->addRoute('polozka/<id [0-9]+>/upravit', 'Item:edit');

		// Attachments
		$router->addRoute('priloha/<id [0-9]+>/stahnout', 'Attachment:download');

		// Members
		$router->addRoute('projekt/<projectId [0-9]+>/clenove', 'Member:default');
		$router->addRoute('projekt/<projectId [0-9]+>/clenove/pozvat', 'Member:invite');
		$router->addRoute('projekt/<projectId [0-9]+>/clenove/<memberId [0-9]+>/role', 'Member:changeRoles');
		$router->addRoute('pozvanka/<token>', 'Member:accept');

		// Fallback
		$router->addRoute('<presenter>/<action>[/<id>]', 'Homepage:default');

		return $router;
	}
}
