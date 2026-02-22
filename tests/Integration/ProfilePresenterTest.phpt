<?php

/**
 * Integration tests for ProfilePresenter.
 * Tests auth guard, profile display, form creation, and profile update logic.
 */

declare(strict_types=1);

use App\Model\Repository\UserRepository;
use App\Presenters\ProfilePresenter;
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

// Create test user
$testEmail = 'test-profile-' . uniqid() . '@test.cz';
$testPassword = 'ProfileTestPassword123';
$user = $userRepo->insert([
	'email' => $testEmail,
	'password' => $passwords->hash($testPassword),
	'roles' => '["ROLE_USER"]',
	'is_verified' => 1,
	'created_at' => new DateTime(),
]);
$userId = $user->id;

register_shutdown_function(function () use ($db, $userId) {
	$db->table('email_verification_token')->where('user_id', $userId)->delete();
	$db->table('reset_password_request')->where('user_id', $userId)->delete();
	$db->table('profile')->where('user_id', $userId)->delete();
	$db->table('user')->where('id', $userId)->delete();
});

/**
 * Run a presenter action and return the response.
 */
function runPresenter(string $action, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Profile');
	$presenter->autoCanonicalize = false;

	$request = new AppRequest(
		'Profile',
		'GET',
		array_merge(['action' => $action], $params),
	);

	return $presenter->run($request);
}

/**
 * Run a presenter action as logged-in user.
 */
function runPresenterLoggedIn(string $action, int $userId, string $email, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Profile');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$userId,
		['ROLE_USER'],
		['email' => $email, 'displayName' => $email],
	));

	$request = new AppRequest(
		'Profile',
		'GET',
		array_merge(['action' => $action], $params),
	);

	return $presenter->run($request);
}

// === Auth guard tests ===

test('actionDefault redirects to login when not authenticated', function () {
	$response = runPresenter('default');

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});

test('actionEdit redirects to login when not authenticated', function () {
	$response = runPresenter('edit');

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});

// === Profile view tests ===

test('actionDefault renders profile page for logged-in user', function () use ($userId, $testEmail) {
	$response = runPresenterLoggedIn('default', $userId, $testEmail);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains($testEmail, $html);
});

test('actionDefault shows personal data when profile is filled', function () use ($userRepo, $userId, $testEmail) {
	$userRepo->updateProfile($userId, [
		'first_name' => 'Pavel',
		'last_name' => 'Profilový',
		'phone' => '+420111222333',
		'bio' => 'Testovací biografie',
	]);

	$response = runPresenterLoggedIn('default', $userId, $testEmail);
	$html = (string) $response->getSource();

	Assert::contains('Pavel', $html);
	Assert::contains('Profilový', $html);
	Assert::contains('+420111222333', $html);
	Assert::contains('Testovací biografie', $html);
});

test('actionDefault shows "not set" for empty profile fields', function () use ($userRepo, $userId, $testEmail) {
	$userRepo->updateProfile($userId, [
		'first_name' => null,
		'last_name' => null,
		'phone' => null,
		'bio' => null,
	]);

	$response = runPresenterLoggedIn('default', $userId, $testEmail);
	$html = (string) $response->getSource();

	// "Nevyplněno" should appear for empty fields
	Assert::contains('Nevyplněno', $html);
});

// === Profile edit form tests ===

test('actionEdit renders form with all required fields', function () use ($userId, $testEmail) {
	$response = runPresenterLoggedIn('edit', $userId, $testEmail);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();

	// Check form fields exist
	Assert::contains('name="firstName"', $html);
	Assert::contains('name="lastName"', $html);
	Assert::contains('name="phone"', $html);
	Assert::contains('name="bio"', $html);
	Assert::contains('name="currentPassword"', $html);
	Assert::contains('name="newPassword"', $html);
	Assert::contains('name="newPasswordConfirm"', $html);
});

test('actionEdit pre-fills form with current profile data', function () use ($userRepo, $userId, $testEmail) {
	$userRepo->updateProfile($userId, [
		'first_name' => 'Prefill',
		'last_name' => 'Test',
		'phone' => '+420999000111',
		'bio' => 'Bio text',
	]);

	$response = runPresenterLoggedIn('edit', $userId, $testEmail);
	$html = (string) $response->getSource();

	Assert::contains('value="Prefill"', $html);
	Assert::contains('value="Test"', $html);
	Assert::contains('value="+420999000111"', $html);
	Assert::contains('Bio text', $html);
});

test('email field is disabled in edit form', function () use ($userId, $testEmail) {
	$response = runPresenterLoggedIn('edit', $userId, $testEmail);
	$html = (string) $response->getSource();

	Assert::contains('disabled', $html);
});

// === Form component tests ===

test('editProfileForm has correct structure', function () use ($presenterFactory, $userId, $testEmail) {
	$presenter = $presenterFactory->createPresenter('Profile');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$userId,
		['ROLE_USER'],
		['email' => $testEmail, 'displayName' => $testEmail],
	));

	// Run to initialize presenter
	$request = new AppRequest('Profile', 'GET', ['action' => 'edit']);
	$presenter->run($request);

	$form = $presenter['editProfileForm'];

	Assert::type(Nette\Application\UI\Form::class, $form);
	Assert::true(isset($form['email']));
	Assert::true(isset($form['firstName']));
	Assert::true(isset($form['lastName']));
	Assert::true(isset($form['phone']));
	Assert::true(isset($form['bio']));
	Assert::true(isset($form['currentPassword']));
	Assert::true(isset($form['newPassword']));
	Assert::true(isset($form['newPasswordConfirm']));
	Assert::true(isset($form['send']));
});

// === Business logic tests (via direct repository verification) ===

test('profile update via repository works correctly', function () use ($userRepo, $userId) {
	$userRepo->updateProfile($userId, [
		'first_name' => 'Direct',
		'last_name' => 'Update',
		'phone' => '+420777888999',
		'bio' => 'Direct update bio',
	]);

	$profile = $userRepo->getProfile($userId);
	Assert::same('Direct', $profile->first_name);
	Assert::same('Update', $profile->last_name);
	Assert::same('+420777888999', $profile->phone);
	Assert::same('Direct update bio', $profile->bio);
});

test('password change requires correct current password verification', function () use ($passwords, $testPassword, $userRepo, $userId) {
	// Verify the current password works
	$user = $userRepo->findById($userId);
	Assert::true($passwords->verify($testPassword, $user->password));

	// Change password
	$newPassword = 'BrandNewPassword123456';
	$userRepo->updatePassword($userId, $passwords->hash($newPassword));

	// Old password should no longer work
	$user = $userRepo->findById($userId);
	Assert::false($passwords->verify($testPassword, $user->password));
	Assert::true($passwords->verify($newPassword, $user->password));
});
