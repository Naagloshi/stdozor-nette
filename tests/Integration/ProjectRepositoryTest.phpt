<?php

/**
 * Integration tests for ProjectRepository and ProjectMemberRepository.
 */

declare(strict_types=1);

use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use App\Model\Repository\UserRepository;
use Nette\Database\Explorer;
use Nette\Security\Passwords;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$container = require __DIR__ . '/../bootstrap.container.php';

/** @var ProjectRepository $projectRepo */
$projectRepo = $container->getByType(ProjectRepository::class);
/** @var ProjectMemberRepository $memberRepo */
$memberRepo = $container->getByType(ProjectMemberRepository::class);
/** @var UserRepository $userRepo */
$userRepo = $container->getByType(UserRepository::class);
/** @var Passwords $passwords */
$passwords = $container->getByType(Passwords::class);
/** @var Explorer $db */
$db = $container->getByType(Explorer::class);

// Create test user
$testEmail = 'test-project-' . uniqid() . '@test.cz';
$user = $userRepo->insert([
	'email' => $testEmail,
	'password' => $passwords->hash('TestPassword123456'),
	'roles' => '["ROLE_USER"]',
	'is_verified' => 1,
	'created_at' => new DateTime(),
]);
$userId = $user->id;
$projectId = null;

register_shutdown_function(function () use ($db, $userId, &$projectId) {
	// project_member is ON DELETE CASCADE from project
	if ($projectId) {
		$db->table('project')->where('id', $projectId)->delete();
	}
	// Also clean any remaining projects by this user
	$db->table('project')->where('owner_id', $userId)->delete();
	$db->table('profile')->where('user_id', $userId)->delete();
	$db->table('user')->where('id', $userId)->delete();
});

test('insert creates project', function () use ($projectRepo, &$projectId, $userId) {
	$project = $projectRepo->insert([
		'name' => 'Testovací stavba',
		'description' => 'Popis testovací stavby',
		'address' => 'Testovací 123, Praha',
		'status' => 'planning',
		'currency' => 'CZK',
		'is_public' => 0,
		'owner_id' => $userId,
		'estimated_amount_cents' => 500000,
		'created_at' => new DateTime(),
	]);

	$projectId = $project->id;

	Assert::true($projectId > 0);
	Assert::same('Testovací stavba', $project->name);
	Assert::same('planning', $project->status);
	Assert::same(500000, $project->estimated_amount_cents);
});

test('findById returns project', function () use ($projectRepo, &$projectId) {
	$project = $projectRepo->findById($projectId);

	Assert::notNull($project);
	Assert::same('Testovací stavba', $project->name);
	Assert::same('Testovací 123, Praha', $project->address);
});

test('findById returns null for nonexistent id', function () use ($projectRepo) {
	Assert::null($projectRepo->findById(999999));
});

test('update changes project fields', function () use ($projectRepo, &$projectId) {
	$projectRepo->update($projectId, [
		'name' => 'Upravená stavba',
		'status' => 'active',
		'estimated_amount_cents' => 750000,
		'updated_at' => new DateTime(),
	]);

	$project = $projectRepo->findById($projectId);
	Assert::same('Upravená stavba', $project->name);
	Assert::same('active', $project->status);
	Assert::same(750000, $project->estimated_amount_cents);
});

test('createOwner creates membership with owner role', function () use ($memberRepo, &$projectId, $userId) {
	$member = $memberRepo->createOwner($projectId, $userId);

	Assert::true($member->id > 0);
	Assert::same($projectId, $member->project_id);
	Assert::same($userId, $member->user_id);
	Assert::true((bool) $member->has_global_category_access);
});

test('findByProjectAndUser returns member', function () use ($memberRepo, &$projectId, $userId) {
	$member = $memberRepo->findByProjectAndUser($projectId, $userId);

	Assert::notNull($member);
	Assert::same($userId, $member->user_id);
});

test('findByProjectAndUser returns null for non-member', function () use ($memberRepo, &$projectId) {
	Assert::null($memberRepo->findByProjectAndUser($projectId, 999999));
});

test('isOwner returns true for owner', function () use ($memberRepo, &$projectId, $userId) {
	Assert::true($memberRepo->isOwner($projectId, $userId));
});

test('isOwner returns false for non-member', function () use ($memberRepo, &$projectId) {
	Assert::false($memberRepo->isOwner($projectId, 999999));
});

test('getRoles returns decoded roles array', function () use ($memberRepo, &$projectId, $userId) {
	$member = $memberRepo->findByProjectAndUser($projectId, $userId);
	$roles = $memberRepo->getRoles($member);

	Assert::type('array', $roles);
	Assert::contains('owner', $roles);
});

test('findByUser returns projects for member', function () use ($projectRepo, &$projectId, $userId) {
	$projects = $projectRepo->findByUser($userId);

	Assert::true(count($projects) >= 1);
	$found = false;
	foreach ($projects as $p) {
		if ($p->id === $projectId) {
			$found = true;
			break;
		}
	}
	Assert::true($found, 'Project should be found in user projects');
});

test('findByUser returns empty for non-member', function () use ($projectRepo) {
	$projects = $projectRepo->findByUser(999999);
	Assert::same([], $projects);
});

test('getCategoryCount returns zero for project without categories', function () use ($projectRepo, &$projectId) {
	Assert::same(0, $projectRepo->getCategoryCount($projectId));
});

test('delete removes project and cascades to members', function () use ($projectRepo, $memberRepo, $db, $userId) {
	// Create a temporary project to delete
	$tempProject = $projectRepo->insert([
		'name' => 'K smazání',
		'status' => 'planning',
		'currency' => 'CZK',
		'is_public' => 0,
		'owner_id' => $userId,
		'created_at' => new DateTime(),
	]);
	$tempId = $tempProject->id;

	$memberRepo->createOwner($tempId, $userId);

	// Verify member exists
	Assert::notNull($memberRepo->findByProjectAndUser($tempId, $userId));

	// Delete
	$projectRepo->delete($tempId);

	Assert::null($projectRepo->findById($tempId));
	Assert::null($memberRepo->findByProjectAndUser($tempId, $userId));
});
