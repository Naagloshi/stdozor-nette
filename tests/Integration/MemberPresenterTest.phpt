<?php

/**
 * Integration tests for MemberPresenter.
 * Tests auth guards, access control, page rendering, form structure, and signals.
 */

declare(strict_types=1);

use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ProjectInvitationRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use App\Model\Repository\UserRepository;
use App\Model\Service\InvitationService;
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
/** @var ProjectRepository $projectRepo */
$projectRepo = $container->getByType(ProjectRepository::class);
/** @var ProjectMemberRepository $memberRepo */
$memberRepo = $container->getByType(ProjectMemberRepository::class);
/** @var ProjectInvitationRepository $invitationRepo */
$invitationRepo = $container->getByType(ProjectInvitationRepository::class);
/** @var InvitationService $invitationService */
$invitationService = $container->getByType(InvitationService::class);
/** @var CategoryRepository $catRepo */
$catRepo = $container->getByType(CategoryRepository::class);
/** @var UserRepository $userRepo */
$userRepo = $container->getByType(UserRepository::class);
/** @var Passwords $passwords */
$passwords = $container->getByType(Passwords::class);
/** @var Explorer $db */
$db = $container->getByType(Explorer::class);

// Create test users
$ownerEmail = 'test-member-owner-' . uniqid() . '@test.cz';
$otherEmail = 'test-member-other-' . uniqid() . '@test.cz';
$thirdEmail = 'test-member-third-' . uniqid() . '@test.cz';

$owner = $userRepo->insert([
	'email' => $ownerEmail,
	'password' => $passwords->hash('TestPassword123456'),
	'roles' => '["ROLE_USER"]',
	'is_verified' => 1,
	'created_at' => new DateTime(),
]);
$ownerId = $owner->id;

$other = $userRepo->insert([
	'email' => $otherEmail,
	'password' => $passwords->hash('TestPassword123456'),
	'roles' => '["ROLE_USER"]',
	'is_verified' => 1,
	'created_at' => new DateTime(),
]);
$otherId = $other->id;

$third = $userRepo->insert([
	'email' => $thirdEmail,
	'password' => $passwords->hash('TestPassword123456'),
	'roles' => '["ROLE_USER"]',
	'is_verified' => 1,
	'created_at' => new DateTime(),
]);
$thirdId = $third->id;

// Create test project + owner membership
$project = $projectRepo->insert([
	'name' => 'Member Test Projekt',
	'status' => 'active',
	'currency' => 'CZK',
	'is_public' => 0,
	'owner_id' => $ownerId,
	'created_at' => new DateTime(),
]);
$projectId = $project->id;
$memberRepo->createOwner($projectId, $ownerId);

// Create test category for invite form
$category = $catRepo->insert([
	'name' => 'Root Category',
	'status' => 'in_progress',
	'project_id' => $projectId,
	'parent_id' => null,
	'display_order' => 0,
	'created_at' => new DateTime(),
]);
$categoryId = $category->id;

register_shutdown_function(function () use ($db, $ownerId, $otherId, $thirdId, $projectId) {
	$db->table('project_invitation')->where('project_id', $projectId)->delete();
	$db->table('category_permission')->where('category_id IN (SELECT id FROM category WHERE project_id = ?)', $projectId)->delete();
	$db->table('category')->where('project_id', $projectId)->delete();
	$db->table('project_member')->where('project_id', $projectId)->delete();
	$db->table('project')->where('id', $projectId)->delete();
	foreach ([$ownerId, $otherId, $thirdId] as $uid) {
		$db->table('profile')->where('user_id', $uid)->delete();
		$db->table('user')->where('id', $uid)->delete();
	}
});

/**
 * Run Member presenter action without auth.
 */
function runMember(string $action, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;

	$request = new AppRequest(
		'Member',
		'GET',
		array_merge(['action' => $action], $params),
	);

	return $presenter->run($request);
}

/**
 * Run Member presenter action as logged-in user.
 */
function runMemberAs(int $userId, string $email, string $action, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$userId,
		['ROLE_USER'],
		['email' => $email, 'displayName' => $email],
	));

	$request = new AppRequest(
		'Member',
		'GET',
		array_merge(['action' => $action], $params),
	);

	return $presenter->run($request);
}

// === Auth guard tests ===

test('actionDefault redirects to login when not authenticated', function () use ($projectId) {
	$response = runMember('default', ['projectId' => $projectId]);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});

test('actionInvite redirects to login when not authenticated', function () use ($projectId) {
	$response = runMember('invite', ['projectId' => $projectId]);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});

test('actionChangeRoles redirects to login when not authenticated', function () use ($projectId) {
	$response = runMember('changeRoles', ['projectId' => $projectId, 'memberId' => 1]);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});

// === Access control tests ===

test('actionDefault renders for project member', function () use ($ownerId, $ownerEmail, $projectId) {
	$response = runMemberAs($ownerId, $ownerEmail, 'default', ['projectId' => $projectId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('Member Test Projekt', $html);
});

test('actionDefault returns 403 for non-member', function () use ($otherId, $otherEmail, $projectId) {
	Assert::exception(
		fn() => runMemberAs($otherId, $otherEmail, 'default', ['projectId' => $projectId]),
		Nette\Application\BadRequestException::class,
		null,
		403,
	);
});

test('actionInvite returns 403 for non-owner member', function () use ($otherId, $otherEmail, $projectId, $db, $memberRepo) {
	// Add other as viewer
	$db->table('project_member')->insert([
		'project_id' => $projectId,
		'user_id' => $otherId,
		'roles' => '["viewer"]',
		'has_global_category_access' => 0,
		'invited_at' => new DateTime(),
	]);

	Assert::exception(
		fn() => runMemberAs($otherId, $otherEmail, 'invite', ['projectId' => $projectId]),
		Nette\Application\BadRequestException::class,
		null,
		403,
	);

	// Clean up
	$memberRepo->findByProjectAndUser($projectId, $otherId)?->delete();
});

// === Rendering tests ===

test('actionDefault renders member list with owner data', function () use ($ownerId, $ownerEmail, $projectId) {
	$response = runMemberAs($ownerId, $ownerEmail, 'default', ['projectId' => $projectId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains($ownerEmail, $html);
});

test('actionInvite renders invite form for owner', function () use ($ownerId, $ownerEmail, $projectId) {
	$response = runMemberAs($ownerId, $ownerEmail, 'invite', ['projectId' => $projectId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('name="email"', $html);
});

// === Form structure tests ===

test('inviteForm has correct fields', function () use ($presenterFactory, $ownerId, $ownerEmail, $projectId) {
	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Member', 'GET', ['action' => 'invite', 'projectId' => $projectId]);
	$presenter->run($request);

	$form = $presenter['inviteForm'];

	Assert::type(Nette\Application\UI\Form::class, $form);
	Assert::true(isset($form['email']));
	Assert::true(isset($form['roles']));
	Assert::true(isset($form['hasGlobalCategoryAccess']));
	Assert::true(isset($form['categories']));
	Assert::true(isset($form['send']));
});

// === Signal tests ===

test('handleRemove removes a member and redirects', function () use ($presenterFactory, $ownerId, $ownerEmail, $projectId, $otherId, $db, $memberRepo) {
	// Add other as contractor
	$otherMember = $db->table('project_member')->insert([
		'project_id' => $projectId,
		'user_id' => $otherId,
		'roles' => '["contractor"]',
		'has_global_category_access' => 0,
		'invited_at' => new DateTime(),
		'accepted_at' => new DateTime(),
	]);

	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Member', 'GET', [
		'action' => 'default',
		'projectId' => $projectId,
		'do' => 'remove',
		'memberId' => $otherMember->id,
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);
	Assert::null($memberRepo->findById($otherMember->id));
});

test('handleRemove returns 403 for non-owner', function () use ($presenterFactory, $otherId, $otherEmail, $projectId, $ownerId, $db, $memberRepo) {
	// Add other as viewer
	$otherMember = $db->table('project_member')->insert([
		'project_id' => $projectId,
		'user_id' => $otherId,
		'roles' => '["viewer"]',
		'has_global_category_access' => 0,
		'invited_at' => new DateTime(),
	]);

	// Get owner membership id
	$ownerMember = $memberRepo->findByProjectAndUser($projectId, $ownerId);

	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$otherId,
		['ROLE_USER'],
		['email' => $otherEmail, 'displayName' => $otherEmail],
	));

	Assert::exception(function () use ($presenter, $projectId, $ownerMember) {
		$request = new AppRequest('Member', 'GET', [
			'action' => 'default',
			'projectId' => $projectId,
			'do' => 'remove',
			'memberId' => $ownerMember->id,
		]);
		$presenter->run($request);
	}, Nette\Application\BadRequestException::class, null, 403);

	// Clean up
	$otherMember->delete();
});

test('handleRemove returns 403 when trying to remove self', function () use ($presenterFactory, $ownerId, $ownerEmail, $projectId, $memberRepo) {
	$ownerMember = $memberRepo->findByProjectAndUser($projectId, $ownerId);

	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	Assert::exception(function () use ($presenter, $projectId, $ownerMember) {
		$request = new AppRequest('Member', 'GET', [
			'action' => 'default',
			'projectId' => $projectId,
			'do' => 'remove',
			'memberId' => $ownerMember->id,
		]);
		$presenter->run($request);
	}, Nette\Application\BadRequestException::class, null, 403);
});

test('handleRemove returns 403 when trying to remove permanent owner', function () use ($presenterFactory, $ownerId, $ownerEmail, $projectId, $thirdId, $db, $memberRepo) {
	// Add third user as second owner
	$thirdMember = $db->table('project_member')->insert([
		'project_id' => $projectId,
		'user_id' => $thirdId,
		'roles' => '["owner"]',
		'has_global_category_access' => 1,
		'invited_at' => new DateTime(),
		'accepted_at' => new DateTime(),
	]);

	// Try to remove permanent owner (project.owner_id) as third user (also owner)
	$ownerMember = $memberRepo->findByProjectAndUser($projectId, $ownerId);

	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$thirdId,
		['ROLE_USER'],
		['email' => 'third@test.cz', 'displayName' => 'third'],
	));

	Assert::exception(function () use ($presenter, $projectId, $ownerMember) {
		$request = new AppRequest('Member', 'GET', [
			'action' => 'default',
			'projectId' => $projectId,
			'do' => 'remove',
			'memberId' => $ownerMember->id,
		]);
		$presenter->run($request);
	}, Nette\Application\BadRequestException::class, null, 403);

	// Clean up
	$thirdMember->delete();
});

test('handleCancelInvitation deletes invitation and redirects', function () use ($presenterFactory, $ownerId, $ownerEmail, $projectId, $invitationRepo) {
	$invitation = $invitationRepo->insert([
		'email' => 'cancel-test@test.cz',
		'roles' => '["contractor"]',
		'token' => bin2hex(random_bytes(32)),
		'expires_at' => new DateTime('+7 days'),
		'project_id' => $projectId,
		'invited_by_id' => $ownerId,
		'category_ids' => '[]',
		'created_at' => new DateTime(),
	]);

	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Member', 'GET', [
		'action' => 'default',
		'projectId' => $projectId,
		'do' => 'cancelInvitation',
		'invitationId' => $invitation->id,
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);
	Assert::null($invitationRepo->findById($invitation->id));
});

// === Accept tests ===

test('actionAccept with valid token for logged-in user creates member', function () use ($presenterFactory, $thirdId, $thirdEmail, $projectId, $invitationRepo, $memberRepo) {
	$invitation = $invitationRepo->insert([
		'email' => 'accept-test@test.cz',
		'roles' => '["contractor"]',
		'token' => bin2hex(random_bytes(32)),
		'expires_at' => new DateTime('+7 days'),
		'project_id' => $projectId,
		'category_ids' => '[]',
		'created_at' => new DateTime(),
	]);

	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$thirdId,
		['ROLE_USER'],
		['email' => $thirdEmail, 'displayName' => $thirdEmail],
	));

	$request = new AppRequest('Member', 'GET', [
		'action' => 'accept',
		'token' => $invitation->token,
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);

	// Check member was created
	$member = $memberRepo->findByProjectAndUser($projectId, $thirdId);
	Assert::notNull($member);
	Assert::contains('contractor', $memberRepo->getRoles($member));

	// Check invitation marked as used
	$updated = $invitationRepo->findById($invitation->id);
	Assert::true((bool) $updated->used);

	// Clean up
	$memberRepo->delete($member->id);
});

test('actionAccept with invalid token redirects with error', function () use ($presenterFactory, $ownerId, $ownerEmail) {
	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Member', 'GET', [
		'action' => 'accept',
		'token' => 'nonexistent_token_1234567890',
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);
});

test('actionAccept for unauthenticated user stores token and redirects to login', function () use ($presenterFactory, $projectId, $invitationRepo) {
	$invitation = $invitationRepo->insert([
		'email' => 'unauth-accept@test.cz',
		'roles' => '["investor"]',
		'token' => bin2hex(random_bytes(32)),
		'expires_at' => new DateTime('+7 days'),
		'project_id' => $projectId,
		'category_ids' => '[]',
		'created_at' => new DateTime(),
	]);

	// Explicitly logout to ensure unauthenticated state (session persists between tests)
	$presenter = $presenterFactory->createPresenter('Member');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->logout(true);

	$request = new Nette\Application\Request('Member', 'GET', [
		'action' => 'accept',
		'token' => $invitation->token,
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());

	// Clean up
	$invitationRepo->delete($invitation->id);
});
