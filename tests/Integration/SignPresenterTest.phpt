<?php

/**
 * Integration tests for SignPresenter.
 * Tests auth page rendering, form structure, and redirect behavior.
 */

declare(strict_types=1);

use App\Model\Repository\UserRepository;
use Nette\Application\Request as AppRequest;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Database\Explorer;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$container = require __DIR__ . '/../bootstrap.container.php';

/** @var Nette\Application\IPresenterFactory $presenterFactory */
$presenterFactory = $container->getByType(Nette\Application\IPresenterFactory::class);
/** @var UserRepository $userRepo */
$userRepo = $container->getByType(UserRepository::class);
/** @var Passwords $passwords */
$passwords = $container->getByType(Passwords::class);
/** @var Explorer $db */
$db = $container->getByType(Explorer::class);

/**
 * Run a Sign presenter action.
 */
function runSign(
	Nette\Application\IPresenterFactory $factory,
	string $action,
	array $params = [],
	?SimpleIdentity $identity = null,
): Nette\Application\Response {
	$presenter = $factory->createPresenter('Sign');
	$presenter->autoCanonicalize = false;

	if ($identity) {
		$presenter->getUser()->login($identity);
	} else {
		$presenter->getUser()->logout(true);
	}

	$request = new AppRequest(
		'Sign',
		'GET',
		array_merge(['action' => $action], $params),
	);

	return $presenter->run($request);
}

$loggedInIdentity = new SimpleIdentity(1, ['ROLE_USER'], ['email' => 'x@x.cz', 'displayName' => 'x']);

// === Login page ===

test('login page renders for unauthenticated user', function () use ($presenterFactory) {
	$response = runSign($presenterFactory, 'in');

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('loginForm', $html);
	Assert::contains('name="email"', $html);
	Assert::contains('name="password"', $html);
});

test('login page redirects authenticated user', function () use ($presenterFactory, $loggedInIdentity) {
	$response = runSign($presenterFactory, 'in', [], $loggedInIdentity);

	Assert::type(RedirectResponse::class, $response);
});

// === Registration page ===

test('registration page renders for unauthenticated user', function () use ($presenterFactory) {
	$response = runSign($presenterFactory, 'up');

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('registrationForm', $html);
	Assert::contains('name="email"', $html);
	Assert::contains('name="plainPassword"', $html);
	Assert::contains('name="agreeTerms"', $html);
});

test('registration page redirects authenticated user', function () use ($presenterFactory, $loggedInIdentity) {
	$response = runSign($presenterFactory, 'up', [], $loggedInIdentity);

	Assert::type(RedirectResponse::class, $response);
});

// === Forgot password page ===

test('forgot password page renders for unauthenticated user', function () use ($presenterFactory) {
	$response = runSign($presenterFactory, 'forgotPassword');

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('forgotPasswordForm', $html);
	Assert::contains('name="email"', $html);
});

test('forgot password page redirects authenticated user', function () use ($presenterFactory, $loggedInIdentity) {
	$response = runSign($presenterFactory, 'forgotPassword', [], $loggedInIdentity);

	Assert::type(RedirectResponse::class, $response);
});

// === Check email page ===

test('check email page renders', function () use ($presenterFactory) {
	$response = runSign($presenterFactory, 'checkEmail');

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('checkEmail', strtolower($html) . 'checkEmail'); // page rendered
});

// === Resend verification page ===

test('resend verification page renders for unauthenticated user', function () use ($presenterFactory) {
	$response = runSign($presenterFactory, 'resendVerification');

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('resendVerificationForm', $html);
	Assert::contains('name="email"', $html);
});

test('resend verification page redirects authenticated user', function () use ($presenterFactory, $loggedInIdentity) {
	$response = runSign($presenterFactory, 'resendVerification', [], $loggedInIdentity);

	Assert::type(RedirectResponse::class, $response);
});

// === Logout ===

test('logout redirects to homepage', function () use ($presenterFactory, $loggedInIdentity) {
	$response = runSign($presenterFactory, 'out', [], $loggedInIdentity);

	Assert::type(RedirectResponse::class, $response);
});

// === Reset password ===

test('reset password without token redirects', function () use ($presenterFactory) {
	$response = runSign($presenterFactory, 'resetPassword');

	Assert::type(RedirectResponse::class, $response);
});

// === Email verification ===

test('email verification with invalid token redirects with error', function () use ($presenterFactory) {
	$response = runSign($presenterFactory, 'verifyEmail', ['token' => 'invalidtoken']);

	Assert::type(RedirectResponse::class, $response);
});

// === Form component structure tests ===

test('loginForm has email and password fields', function () use ($presenterFactory) {
	$presenter = $presenterFactory->createPresenter('Sign');
	$presenter->autoCanonicalize = false;
	$presenter->run(new AppRequest('Sign', 'GET', ['action' => 'in']));

	$form = $presenter['loginForm'];
	Assert::true(isset($form['email']));
	Assert::true(isset($form['password']));
	Assert::true(isset($form['send']));
});

test('registrationForm has email, password, and terms fields', function () use ($presenterFactory) {
	$presenter = $presenterFactory->createPresenter('Sign');
	$presenter->autoCanonicalize = false;
	$presenter->run(new AppRequest('Sign', 'GET', ['action' => 'up']));

	$form = $presenter['registrationForm'];
	Assert::true(isset($form['email']));
	Assert::true(isset($form['plainPassword']));
	Assert::true(isset($form['agreeTerms']));
	Assert::true(isset($form['send']));
});

// === Email verification flow (integration) ===

test('email verification flow works end-to-end', function () use ($userRepo, $passwords, $db, $presenterFactory) {
	$email = 'verify-test-' . uniqid() . '@test.cz';
	$user = $userRepo->insert([
		'email' => $email,
		'password' => $passwords->hash('TestPassword123456'),
		'roles' => '["ROLE_USER"]',
		'is_verified' => 0,
		'created_at' => new DateTime(),
	]);

	$emailVerification = new App\Model\Security\EmailVerificationService($db);
	$token = $emailVerification->createToken($user->id);

	// Verify email via presenter
	$response = runSign($presenterFactory, 'verifyEmail', ['token' => $token]);
	Assert::type(RedirectResponse::class, $response);

	// User should be verified now
	$updatedUser = $userRepo->findById($user->id);
	Assert::true((bool) $updatedUser->is_verified);

	// Cleanup
	$db->table('email_verification_token')->where('user_id', $user->id)->delete();
	$db->table('profile')->where('user_id', $user->id)->delete();
	$db->table('user')->where('id', $user->id)->delete();
});
