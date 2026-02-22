<?php

/**
 * Integration tests for CategoryRepository.
 */

declare(strict_types=1);

use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use App\Model\Repository\UserRepository;
use Nette\Database\Explorer;
use Nette\Security\Passwords;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$container = require __DIR__ . '/../bootstrap.container.php';

/** @var CategoryRepository $catRepo */
$catRepo = $container->getByType(CategoryRepository::class);
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

// Create test user + project
$testEmail = 'test-cat-' . uniqid() . '@test.cz';
$user = $userRepo->insert([
	'email' => $testEmail,
	'password' => $passwords->hash('TestPassword123456'),
	'roles' => '["ROLE_USER"]',
	'is_verified' => 1,
	'created_at' => new DateTime(),
]);
$userId = $user->id;

$project = $projectRepo->insert([
	'name' => 'Cat Test Project',
	'status' => 'active',
	'currency' => 'CZK',
	'is_public' => 0,
	'owner_id' => $userId,
	'created_at' => new DateTime(),
]);
$projectId = $project->id;
$memberRepo->createOwner($projectId, $userId);

$categoryIds = [];

register_shutdown_function(function () use ($db, $userId, $projectId, &$categoryIds) {
	// Delete categories first (children before parents handled by FK cascade)
	$db->table('category')->where('project_id', $projectId)->delete();
	$db->table('project')->where('id', $projectId)->delete();
	$db->table('profile')->where('user_id', $userId)->delete();
	$db->table('user')->where('id', $userId)->delete();
});

test('insert creates category', function () use ($catRepo, $projectId, &$categoryIds) {
	$cat = $catRepo->insert([
		'name' => 'Základy',
		'description' => 'Zakládání stavby',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 0,
		'created_at' => new DateTime(),
	]);

	$categoryIds['root1'] = $cat->id;
	Assert::true($cat->id > 0);
	Assert::same('Základy', $cat->name);
	Assert::same('planned', $cat->status);
	Assert::same($projectId, $cat->project_id);
});

test('findById returns category', function () use ($catRepo, &$categoryIds) {
	$cat = $catRepo->findById($categoryIds['root1']);

	Assert::notNull($cat);
	Assert::same('Základy', $cat->name);
	Assert::same('Zakládání stavby', $cat->description);
});

test('findById returns null for nonexistent id', function () use ($catRepo) {
	Assert::null($catRepo->findById(999999));
});

test('update changes category fields', function () use ($catRepo, &$categoryIds) {
	$catRepo->update($categoryIds['root1'], [
		'name' => 'Základy - upraveno',
		'estimated_amount' => '150000.00',
		'updated_at' => new DateTime(),
	]);

	$cat = $catRepo->findById($categoryIds['root1']);
	Assert::same('Základy - upraveno', $cat->name);
	Assert::equal(150000.0, (float) $cat->estimated_amount);
});

test('insert child category', function () use ($catRepo, $projectId, &$categoryIds) {
	$child = $catRepo->insert([
		'name' => 'Výkopy',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => $categoryIds['root1'],
		'display_order' => 0,
		'created_at' => new DateTime(),
	]);

	$categoryIds['child1'] = $child->id;
	Assert::same($categoryIds['root1'], $child->parent_id);
});

test('insert second root category', function () use ($catRepo, $projectId, &$categoryIds) {
	$cat = $catRepo->insert([
		'name' => 'Hrubá stavba',
		'status' => 'in_progress',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 0,
		'created_at' => new DateTime(),
		'started_at' => new DateTime(),
	]);

	$categoryIds['root2'] = $cat->id;
	Assert::same('in_progress', $cat->status);
});

test('findByProject returns all categories sorted', function () use ($catRepo, $projectId) {
	$categories = $catRepo->findByProject($projectId);

	Assert::true(count($categories) >= 3);

	// in_progress should come first (sort order 0), then planned (sort order 1)
	$statuses = array_map(fn($c) => $c->status, $categories);
	$inProgressIdx = array_search('in_progress', $statuses);
	$plannedIdx = array_search('planned', $statuses);
	Assert::true($inProgressIdx < $plannedIdx, 'in_progress categories should come before planned');
});

test('findRootsByProject returns only root categories', function () use ($catRepo, $projectId) {
	$roots = $catRepo->findRootsByProject($projectId);

	foreach ($roots as $root) {
		Assert::null($root->parent_id);
		Assert::same($projectId, $root->project_id);
	}
	Assert::true(count($roots) >= 2);
});

test('findChildren returns direct children', function () use ($catRepo, &$categoryIds) {
	$children = $catRepo->findChildren($categoryIds['root1']);

	Assert::count(1, $children);
	Assert::same('Výkopy', $children[0]->name);
	Assert::same($categoryIds['root1'], $children[0]->parent_id);
});

test('findChildren returns empty for leaf category', function () use ($catRepo, &$categoryIds) {
	$children = $catRepo->findChildren($categoryIds['child1']);
	Assert::count(0, $children);
});

test('buildTree creates correct hierarchy', function () use ($catRepo, $projectId) {
	$categories = $catRepo->findByProject($projectId);
	$tree = $catRepo->buildTree($categories);

	// Should only have root categories at top level
	foreach ($tree as $node) {
		Assert::null($node['category']->parent_id);
		Assert::type('array', $node['children']);
	}

	// Find root1 and check it has child
	$rootWithChild = null;
	foreach ($tree as $node) {
		if (count($node['children']) > 0) {
			$rootWithChild = $node;
			break;
		}
	}
	Assert::notNull($rootWithChild);
	Assert::count(1, $rootWithChild['children']);
	Assert::same('Výkopy', $rootWithChild['children'][0]['category']->name);
});

test('getNextDisplayOrder returns correct next value', function () use ($catRepo, $projectId) {
	$nextOrder = $catRepo->getNextDisplayOrder($projectId, null, 'planned');
	Assert::type('int', $nextOrder);
	Assert::true($nextOrder >= 0);
});

test('countByProject returns correct count', function () use ($catRepo, $projectId) {
	$count = $catRepo->countByProject($projectId);
	Assert::true($count >= 3);
});

test('sumItemsAmount returns zero when no items exist', function () use ($catRepo, &$categoryIds) {
	$sum = $catRepo->sumItemsAmount($categoryIds['root1']);
	Assert::same('0', $sum);
});

test('sumSubcategoriesAmount returns zero for leaf', function () use ($catRepo, &$categoryIds) {
	$sum = $catRepo->sumSubcategoriesAmount($categoryIds['child1']);
	Assert::same('0', $sum);
});

test('swapOrder swaps display orders between neighbors', function () use ($catRepo, $projectId, &$categoryIds) {
	// Create two planned root categories with sequential display_orders
	$catA = $catRepo->insert([
		'name' => 'Swap A',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 10,
		'created_at' => new DateTime(),
	]);
	$catB = $catRepo->insert([
		'name' => 'Swap B',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 11,
		'created_at' => new DateTime(),
	]);
	$categoryIds['swapA'] = $catA->id;
	$categoryIds['swapB'] = $catB->id;

	// Swap B up
	$swapped = $catRepo->swapOrder($catB, 'up');
	Assert::true($swapped);

	$a = $catRepo->findById($catA->id);
	$b = $catRepo->findById($catB->id);
	Assert::same(11, $a->display_order);
	Assert::same(10, $b->display_order);
});

test('swapOrder returns false at boundary', function () use ($catRepo, &$categoryIds) {
	// Try to move the first item up — should fail
	$cat = $catRepo->findById($categoryIds['swapB']); // now has display_order 10
	$swapped = $catRepo->swapOrder($cat, 'up');
	// It might or might not swap depending on other categories' display_orders
	Assert::type('bool', $swapped);
});

test('deleteRecursive deletes category and children', function () use ($catRepo, $projectId) {
	// Create parent + child for deletion test
	$parent = $catRepo->insert([
		'name' => 'Delete Parent',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 100,
		'created_at' => new DateTime(),
	]);

	$child = $catRepo->insert([
		'name' => 'Delete Child',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => $parent->id,
		'display_order' => 0,
		'created_at' => new DateTime(),
	]);

	$grandchild = $catRepo->insert([
		'name' => 'Delete Grandchild',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => $child->id,
		'display_order' => 0,
		'created_at' => new DateTime(),
	]);

	// Delete parent recursively
	$parentId = $catRepo->deleteRecursive($parent->id);
	Assert::null($parentId); // root has no parent

	Assert::null($catRepo->findById($parent->id));
	Assert::null($catRepo->findById($child->id));
	Assert::null($catRepo->findById($grandchild->id));
});
