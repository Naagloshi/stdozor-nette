<?php

/**
 * Bootstrap for integration tests that need DI container and database.
 * Returns a Nette\DI\Container instance.
 *
 * Note: Tester\Environment::setup() is NOT called here — it must be called
 * in the .phpt file BEFORE requiring this bootstrap, or via the simple bootstrap.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Nette v3.2+ requires _nss cookie for same-origin signal validation.
// In tests there's no browser, so we fake the cookie.
$_COOKIE['_nss'] = '1';

$configurator = App\Bootstrap::boot();
$configurator->setTempDirectory(__DIR__ . '/../temp/tests');

return $configurator->createContainer();
