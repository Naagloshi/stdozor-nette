<?php

/**
 * Integration tests for UserRepository.
 */

declare(strict_types=1);

use App\Model\Repository\UserRepository;
use Nette\Database\Explorer;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$container = require __DIR__ . '/../bootstrap.container.php';

/** @var UserRepository $repo */
$repo = $container->getByType(UserRepository::class);
/** @var Explorer $db */
$db = $container->getByType(Explorer::class);

$testEmail = 'test-repo-' . uniqid() . '@test.cz';
$userId = null;

register_shutdown_function(function () use ($db, &$userId) {
	if ($userId) {
		$db->table('email_verification_token')->where('user_id', $userId)->delete();
		$db->table('reset_password_request')->where('user_id', $userId)->delete();
		$db->table('profile')->where('user_id', $userId)->delete();
		$db->table('user')->where('id', $userId)->delete();
	}
});


test('insert creates user and profile', function () use ($repo, $db, $testEmail, &$userId) {
	$user = $repo->insert([
		'email' => $testEmail,
		'password' => '$2y$10$hash',
		'roles' => '["ROLE_USER"]',
		'is_verified' => 0,
		'created_at' => new DateTime(),
	]);

	$userId = $user->id;

	Assert::true($userId > 0);
	Assert::same($testEmail, $user->email);

	// Profile should be auto-created
	$profile = $db->table('profile')->where('user_id', $userId)->fetch();
	Assert::notNull($profile);
});


test('findById returns user row', function () use ($repo, &$userId, $testEmail) {
	$user = $repo->findById($userId);

	Assert::notNull($user);
	Assert::same($testEmail, $user->email);
});


test('findById returns null for nonexistent id', function () use ($repo) {
	Assert::null($repo->findById(999999));
});


test('findByEmail returns user row', function () use ($repo, $testEmail, &$userId) {
	$user = $repo->findByEmail($testEmail);

	Assert::notNull($user);
	Assert::same($userId, $user->id);
});


test('findByEmail returns null for nonexistent email', function () use ($repo) {
	Assert::null($repo->findByEmail('nobody-' . uniqid() . '@test.cz'));
});


test('getProfile returns profile for existing user', function () use ($repo, &$userId) {
	$profile = $repo->getProfile($userId);

	Assert::notNull($profile);
	Assert::same($userId, $profile->user_id);
});


test('updateProfile updates profile fields', function () use ($repo, &$userId) {
	$repo->updateProfile($userId, [
		'first_name' => 'Testík',
		'last_name' => 'Repositář',
		'phone' => '+420999888777',
		'bio' => 'Test bio text',
	]);

	$profile = $repo->getProfile($userId);
	Assert::same('Testík', $profile->first_name);
	Assert::same('Repositář', $profile->last_name);
	Assert::same('+420999888777', $profile->phone);
	Assert::same('Test bio text', $profile->bio);
});


test('updateProfile sets fields to null', function () use ($repo, &$userId) {
	$repo->updateProfile($userId, [
		'first_name' => null,
		'last_name' => null,
		'phone' => null,
		'bio' => null,
	]);

	$profile = $repo->getProfile($userId);
	Assert::null($profile->first_name);
	Assert::null($profile->last_name);
	Assert::null($profile->phone);
	Assert::null($profile->bio);
});


test('setVerified marks user as verified', function () use ($repo, $db, &$userId) {
	$repo->setVerified($userId);

	$user = $db->table('user')->get($userId);
	Assert::true((bool) $user->is_verified);
});


test('updatePassword changes user password hash', function () use ($repo, $db, &$userId) {
	$repo->updatePassword($userId, '$2y$10$newhash');

	$user = $db->table('user')->get($userId);
	Assert::same('$2y$10$newhash', $user->password);
});


test('deleteUnverified removes user, profile, and tokens', function () use ($repo, $db) {
	// Create a temporary user to delete
	$tempUser = $repo->insert([
		'email' => 'delete-me-' . uniqid() . '@test.cz',
		'password' => '$2y$10$hash',
		'roles' => '["ROLE_USER"]',
		'is_verified' => 0,
		'created_at' => new DateTime(),
	]);

	$tempId = $tempUser->id;

	// Add a verification token
	$db->table('email_verification_token')->insert([
		'token' => bin2hex(random_bytes(32)),
		'user_id' => $tempId,
		'expires_at' => new DateTime('+1 hour'),
		'used' => 0,
		'created_at' => new DateTime(),
	]);

	$repo->deleteUnverified($tempId);

	Assert::null($db->table('user')->get($tempId));
	Assert::same(0, $db->table('profile')->where('user_id', $tempId)->count());
	Assert::same(0, $db->table('email_verification_token')->where('user_id', $tempId)->count());
});
