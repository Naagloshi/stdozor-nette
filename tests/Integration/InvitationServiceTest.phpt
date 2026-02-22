<?php

/**
 * Integration tests for InvitationService.
 * Tests invitation creation, token verification, acceptance, and cancellation.
 */

declare(strict_types=1);

use App\Model\Repository\CategoryPermissionRepository;
use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ProjectInvitationRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use App\Model\Repository\UserRepository;
use App\Model\Service\InvitationService;
use Nette\Database\Explorer;
use Nette\Security\Passwords;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$container = require __DIR__ . '/../bootstrap.container.php';

/** @var InvitationService $service */
$service = $container->getByType(InvitationService::class);
/** @var ProjectInvitationRepository $invitationRepo */
$invitationRepo = $container->getByType(ProjectInvitationRepository::class);
/** @var ProjectMemberRepository $memberRepo */
$memberRepo = $container->getByType(ProjectMemberRepository::class);
/** @var CategoryPermissionRepository $catPermRepo */
$catPermRepo = $container->getByType(CategoryPermissionRepository::class);
/** @var CategoryRepository $catRepo */
$catRepo = $container->getByType(CategoryRepository::class);
/** @var ProjectRepository $projectRepo */
$projectRepo = $container->getByType(ProjectRepository::class);
/** @var UserRepository $userRepo */
$userRepo = $container->getByType(UserRepository::class);
/** @var Passwords $passwords */
$passwords = $container->getByType(Passwords::class);
/** @var Explorer $db */
$db = $container->getByType(Explorer::class);

// Create test users
$ownerEmail = 'test-inv-owner-' . uniqid() . '@test.cz';
$accepterEmail = 'test-inv-accepter-' . uniqid() . '@test.cz';

$owner = $userRepo->insert([
	'email' => $ownerEmail,
	'password' => $passwords->hash('TestPassword123456'),
	'roles' => '["ROLE_USER"]',
	'is_verified' => 1,
	'created_at' => new DateTime(),
]);
$ownerId = $owner->id;

$accepter = $userRepo->insert([
	'email' => $accepterEmail,
	'password' => $passwords->hash('TestPassword123456'),
	'roles' => '["ROLE_USER"]',
	'is_verified' => 1,
	'created_at' => new DateTime(),
]);
$accepterId = $accepter->id;

// Create test project
$project = $projectRepo->insert([
	'name' => 'Invitation Service Test',
	'status' => 'active',
	'currency' => 'CZK',
	'is_public' => 0,
	'owner_id' => $ownerId,
	'created_at' => new DateTime(),
]);
$projectId = $project->id;
$memberRepo->createOwner($projectId, $ownerId);

// Create test category
$category = $catRepo->insert([
	'name' => 'Invitation Test Category',
	'status' => 'in_progress',
	'project_id' => $projectId,
	'parent_id' => null,
	'display_order' => 0,
	'created_at' => new DateTime(),
]);
$categoryId = $category->id;

register_shutdown_function(function () use ($db, $ownerId, $accepterId, $projectId) {
	$db->table('project_invitation')->where('project_id', $projectId)->delete();
	$db->table('category_permission')->where('category_id IN (SELECT id FROM category WHERE project_id = ?)', $projectId)->delete();
	$db->table('category')->where('project_id', $projectId)->delete();
	$db->table('project_member')->where('project_id', $projectId)->delete();
	$db->table('project')->where('id', $projectId)->delete();
	foreach ([$ownerId, $accepterId] as $uid) {
		$db->table('profile')->where('user_id', $uid)->delete();
		$db->table('user')->where('id', $uid)->delete();
	}
});


// === createInvitation tests ===

test('createInvitation creates invitation with valid token', function () use ($service, $invitationRepo, $projectId, $ownerId) {
	$invitation = $service->createInvitation(
		$projectId,
		'invite-test-' . uniqid() . '@test.cz',
		['contractor'],
		$ownerId,
		[],
	);

	Assert::notNull($invitation);
	Assert::same(64, strlen($invitation->token)); // bin2hex(32 bytes) = 64 chars
	Assert::same($projectId, $invitation->project_id);
	Assert::false((bool) $invitation->used);

	// Verify expiry is ~7 days from now
	$expiresAt = $invitation->expires_at instanceof \DateTimeInterface
		? $invitation->expires_at
		: new DateTime($invitation->expires_at);
	$diff = $expiresAt->diff(new DateTime());
	Assert::true($diff->days >= 6 && $diff->days <= 7);

	// Clean up
	$invitationRepo->delete($invitation->id);
});


test('createInvitation throws exception for duplicate email+project', function () use ($service, $invitationRepo, $projectId, $ownerId) {
	$email = 'dup-test-' . uniqid() . '@test.cz';

	$invitation = $service->createInvitation($projectId, $email, ['contractor'], $ownerId, []);

	Assert::exception(
		fn() => $service->createInvitation($projectId, $email, ['investor'], $ownerId, []),
		\RuntimeException::class,
	);

	// Clean up
	$invitationRepo->delete($invitation->id);
});


// === verifyToken tests ===

test('verifyToken returns invitation for valid token', function () use ($service, $invitationRepo, $projectId, $ownerId) {
	$invitation = $service->createInvitation(
		$projectId,
		'verify-test-' . uniqid() . '@test.cz',
		['investor'],
		$ownerId,
		[],
	);

	$verified = $service->verifyToken($invitation->token);
	Assert::notNull($verified);
	Assert::same($invitation->id, $verified->id);

	// Clean up
	$invitationRepo->delete($invitation->id);
});


test('verifyToken returns null for nonexistent token', function () use ($service) {
	$result = $service->verifyToken('nonexistent_token_12345678901234567890123456789012');
	Assert::null($result);
});


test('verifyToken returns null for expired invitation', function () use ($service, $invitationRepo, $projectId, $ownerId) {
	// Insert directly with past expiry
	$invitation = $invitationRepo->insert([
		'email' => 'expired-' . uniqid() . '@test.cz',
		'roles' => '["contractor"]',
		'token' => bin2hex(random_bytes(32)),
		'expires_at' => new DateTime('-1 day'),
		'project_id' => $projectId,
		'invited_by_id' => $ownerId,
		'category_ids' => '[]',
		'created_at' => new DateTime('-8 days'),
	]);

	$result = $service->verifyToken($invitation->token);
	Assert::null($result);

	// Clean up
	$invitationRepo->delete($invitation->id);
});


test('verifyToken returns null for used invitation', function () use ($service, $invitationRepo, $projectId, $ownerId) {
	$invitation = $invitationRepo->insert([
		'email' => 'used-' . uniqid() . '@test.cz',
		'roles' => '["contractor"]',
		'token' => bin2hex(random_bytes(32)),
		'expires_at' => new DateTime('+7 days'),
		'project_id' => $projectId,
		'invited_by_id' => $ownerId,
		'category_ids' => '[]',
		'created_at' => new DateTime(),
		'used' => 1,
		'accepted_at' => new DateTime(),
	]);

	$result = $service->verifyToken($invitation->token);
	Assert::null($result);

	// Clean up
	$invitationRepo->delete($invitation->id);
});


// === acceptInvitation tests ===

test('acceptInvitation creates new member for new user', function () use ($service, $invitationRepo, $memberRepo, $projectId, $ownerId, $accepterId) {
	$invitation = $service->createInvitation(
		$projectId,
		'accept-new-' . uniqid() . '@test.cz',
		['contractor'],
		$ownerId,
		[],
	);

	$member = $service->acceptInvitation($invitation, $accepterId);
	Assert::notNull($member);
	Assert::same($accepterId, $member->user_id);
	Assert::same($projectId, $member->project_id);
	Assert::contains('contractor', $memberRepo->getRoles($member));

	// Check invitation is marked used
	$updated = $invitationRepo->findById($invitation->id);
	Assert::true((bool) $updated->used);
	Assert::notNull($updated->accepted_at);

	// Clean up
	$memberRepo->delete($member->id);
});


test('acceptInvitation merges roles for existing member', function () use ($service, $invitationRepo, $memberRepo, $projectId, $ownerId, $accepterId, $db) {
	// Create accepter as existing member with contractor role
	$existingMember = $db->table('project_member')->insert([
		'project_id' => $projectId,
		'user_id' => $accepterId,
		'roles' => '["contractor"]',
		'has_global_category_access' => 0,
		'invited_at' => new DateTime(),
		'accepted_at' => new DateTime(),
	]);

	$invitation = $service->createInvitation(
		$projectId,
		'merge-' . uniqid() . '@test.cz',
		['investor'],
		$ownerId,
		[],
	);

	$member = $service->acceptInvitation($invitation, $accepterId);

	// Check roles were merged
	$roles = $memberRepo->getRoles($memberRepo->findById($existingMember->id));
	Assert::contains('contractor', $roles);
	Assert::contains('investor', $roles);

	// Clean up
	$existingMember->delete();
});


test('acceptInvitation creates category permissions for contractor', function () use ($service, $invitationRepo, $memberRepo, $catPermRepo, $projectId, $ownerId, $accepterId, $categoryId) {
	$invitation = $service->createInvitation(
		$projectId,
		'catperm-' . uniqid() . '@test.cz',
		['contractor'],
		$ownerId,
		[$categoryId],
	);

	$member = $service->acceptInvitation($invitation, $accepterId);

	// Check category permission was created
	$perms = $catPermRepo->getCategoryIdsForMember($member->id);
	Assert::contains($categoryId, $perms);

	// Clean up
	$catPermRepo->revokeAllForMember($member->id);
	$memberRepo->delete($member->id);
});


test('acceptInvitation does not create category permissions for owner role', function () use ($service, $invitationRepo, $memberRepo, $catPermRepo, $projectId, $ownerId, $accepterId, $categoryId) {
	$invitation = $service->createInvitation(
		$projectId,
		'ownerperm-' . uniqid() . '@test.cz',
		['owner'],
		$ownerId,
		[$categoryId],
	);

	$member = $service->acceptInvitation($invitation, $accepterId);

	// Owner should have global access, no explicit permissions
	Assert::true((bool) $member->has_global_category_access);
	$perms = $catPermRepo->getCategoryIdsForMember($member->id);
	Assert::count(0, $perms);

	// Clean up
	$memberRepo->delete($member->id);
});


// === cancelInvitation tests ===

test('cancelInvitation deletes the invitation', function () use ($service, $invitationRepo, $projectId, $ownerId) {
	$invitation = $service->createInvitation(
		$projectId,
		'cancel-' . uniqid() . '@test.cz',
		['contractor'],
		$ownerId,
		[],
	);

	$service->cancelInvitation($invitation->id);
	Assert::null($invitationRepo->findById($invitation->id));
});
