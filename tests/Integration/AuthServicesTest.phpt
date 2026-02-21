<?php

/**
 * Integration tests for authentication services:
 * - UserAuthenticator
 * - EmailVerificationService
 * - PasswordResetService
 */

declare(strict_types=1);

use App\Model\Repository\UserRepository;
use App\Model\Security\EmailVerificationService;
use App\Model\Security\PasswordResetService;
use App\Model\Security\UserAuthenticator;
use Nette\Security\AuthenticationException;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$container = require __DIR__ . '/../bootstrap.container.php';

/** @var UserRepository $userRepo */
$userRepo = $container->getByType(UserRepository::class);
/** @var Passwords $passwords */
$passwords = $container->getByType(Passwords::class);
/** @var UserAuthenticator $authenticator */
$authenticator = $container->getByType(UserAuthenticator::class);
/** @var EmailVerificationService $emailVerification */
$emailVerification = $container->getByType(EmailVerificationService::class);
/** @var PasswordResetService $passwordReset */
$passwordReset = $container->getByType(PasswordResetService::class);
/** @var Nette\Database\Explorer $db */
$db = $container->getByType(Nette\Database\Explorer::class);


// --- Setup: create a test user ---

$testEmail = 'test-auth-' . uniqid() . '@test.cz';
$testPassword = 'SecureTestPassword123456';
$hashedPassword = $passwords->hash($testPassword);

$user = $userRepo->insert([
	'email' => $testEmail,
	'password' => $hashedPassword,
	'roles' => '["ROLE_USER"]',
	'is_verified' => 0,
	'created_at' => new DateTime(),
]);
$userId = $user->id;


// --- Cleanup function ---

function cleanup(Nette\Database\Explorer $db, int $userId): void
{
	$db->table('email_verification_token')->where('user_id', $userId)->delete();
	$db->table('reset_password_request')->where('user_id', $userId)->delete();
	$db->table('profile')->where('user_id', $userId)->delete();
	$db->table('user')->where('id', $userId)->delete();
}

register_shutdown_function('cleanup', $db, $userId);


// === UserAuthenticator tests ===

test('authenticate throws exception for unknown email', function () use ($authenticator) {
	Assert::exception(
		fn() => $authenticator->authenticate('nonexistent@test.cz', 'password'),
		AuthenticationException::class,
	);
});


test('authenticate throws exception for wrong password', function () use ($authenticator, $testEmail) {
	Assert::exception(
		fn() => $authenticator->authenticate($testEmail, 'wrong-password'),
		AuthenticationException::class,
	);
});


test('authenticate throws exception for unverified user', function () use ($authenticator, $testEmail, $testPassword) {
	Assert::exception(
		fn() => $authenticator->authenticate($testEmail, $testPassword),
		AuthenticationException::class,
	);
});


// === EmailVerificationService tests ===

test('createToken generates 64-char hex token', function () use ($emailVerification, $userId) {
	$token = $emailVerification->createToken($userId);

	Assert::same(64, strlen($token));
	Assert::true(ctype_xdigit($token));
});


test('verify marks user as verified and token as used', function () use ($emailVerification, $userId, $db) {
	$token = $emailVerification->createToken($userId);
	$verifiedUser = $emailVerification->verify($token);

	Assert::same($userId, $verifiedUser->id);
	Assert::true((bool) $db->table('user')->get($userId)->is_verified);

	// Token should be marked as used
	$tokenRow = $db->table('email_verification_token')->where('token', $token)->fetch();
	Assert::true((bool) $tokenRow->used);
});


test('verify throws exception for already used token', function () use ($emailVerification, $userId) {
	$token = $emailVerification->createToken($userId);
	$emailVerification->verify($token);

	Assert::exception(
		fn() => $emailVerification->verify($token),
		RuntimeException::class,
	);
});


test('verify throws exception for invalid token', function () use ($emailVerification) {
	Assert::exception(
		fn() => $emailVerification->verify('nonexistent-token'),
		RuntimeException::class,
	);
});


test('verify throws exception for expired token', function () use ($emailVerification, $userId, $db) {
	$token = $emailVerification->createToken($userId);

	// Manually expire the token
	$db->table('email_verification_token')
		->where('token', $token)
		->update(['expires_at' => new DateTime('-1 hour')]);

	Assert::exception(
		fn() => $emailVerification->verify($token),
		RuntimeException::class,
	);
});


// === UserAuthenticator - after verification ===

test('authenticate returns SimpleIdentity for verified user', function () use ($authenticator, $testEmail, $testPassword) {
	$identity = $authenticator->authenticate($testEmail, $testPassword);

	Assert::type(SimpleIdentity::class, $identity);
	Assert::same($testEmail, $identity->email);
	Assert::same(['ROLE_USER'], $identity->getRoles());
});


test('authenticate sets displayName from profile', function () use ($authenticator, $userRepo, $testEmail, $testPassword, $userId) {
	$userRepo->updateProfile($userId, [
		'first_name' => 'Jan',
		'last_name' => 'Tester',
	]);

	$identity = $authenticator->authenticate($testEmail, $testPassword);
	Assert::same('Jan Tester', $identity->displayName);
});


test('authenticate falls back to email when profile has no name', function () use ($authenticator, $userRepo, $testEmail, $testPassword, $userId) {
	$userRepo->updateProfile($userId, [
		'first_name' => null,
		'last_name' => null,
	]);

	$identity = $authenticator->authenticate($testEmail, $testPassword);
	Assert::same($testEmail, $identity->displayName);
});


// === PasswordResetService tests ===

test('createResetToken returns 60-char token', function () use ($passwordReset, $userId) {
	$token = $passwordReset->createResetToken($userId);

	Assert::same(60, strlen($token));
	Assert::true(ctype_xdigit($token));
});


test('validateTokenAndFetchUser returns user for valid token', function () use ($passwordReset, $userId) {
	$token = $passwordReset->createResetToken($userId);
	$user = $passwordReset->validateTokenAndFetchUser($token);

	Assert::notNull($user);
	Assert::same($userId, $user->id);
});


test('validateTokenAndFetchUser returns null for invalid token', function () use ($passwordReset) {
	$result = $passwordReset->validateTokenAndFetchUser('invalid-token-that-does-not-exist-in-db');

	Assert::null($result);
});


test('validateTokenAndFetchUser returns null for tampered token', function () use ($passwordReset, $userId) {
	$token = $passwordReset->createResetToken($userId);

	// Keep selector (first 20), change verifier
	$tamperedToken = substr($token, 0, 20) . str_repeat('0', 40);
	$result = $passwordReset->validateTokenAndFetchUser($tamperedToken);

	Assert::null($result);
});


test('validateTokenAndFetchUser returns null for expired token', function () use ($passwordReset, $userId, $db) {
	$token = $passwordReset->createResetToken($userId);
	$selector = substr($token, 0, 20);

	// Manually expire the token
	$db->table('reset_password_request')
		->where('selector', $selector)
		->update(['expires_at' => new DateTime('-1 hour')]);

	$result = $passwordReset->validateTokenAndFetchUser($token);
	Assert::null($result);
});


test('removeResetRequest deletes the token from DB', function () use ($passwordReset, $userId, $db) {
	$token = $passwordReset->createResetToken($userId);
	$selector = substr($token, 0, 20);

	Assert::true($db->table('reset_password_request')->where('selector', $selector)->count() > 0);

	$passwordReset->removeResetRequest($token);

	Assert::same(0, $db->table('reset_password_request')->where('selector', $selector)->count());
});


test('createResetToken deletes old tokens for same user', function () use ($passwordReset, $userId, $db) {
	$token1 = $passwordReset->createResetToken($userId);
	$selector1 = substr($token1, 0, 20);

	$token2 = $passwordReset->createResetToken($userId);
	$selector2 = substr($token2, 0, 20);

	// First token should be deleted
	Assert::same(0, $db->table('reset_password_request')->where('selector', $selector1)->count());
	// Second token should exist
	Assert::same(1, $db->table('reset_password_request')->where('selector', $selector2)->count());
});
