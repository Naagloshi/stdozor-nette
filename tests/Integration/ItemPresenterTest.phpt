<?php

/**
 * Integration tests for ItemPresenter.
 * Tests auth guards, page rendering, form structure, and role-based field visibility.
 */

declare(strict_types=1);

use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ItemRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use App\Model\Repository\UserRepository;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\Request as AppRequest;
use Nette\Database\Explorer;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$container = require __DIR__ . '/../bootstrap.container.php';

/** @var Nette\Application\IPresenterFactory $presenterFactory */
$presenterFactory = $container->getByType(Nette\Application\IPresenterFactory::class);
/** @var ItemRepository $itemRepo */
$itemRepo = $container->getByType(ItemRepository::class);
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

// Create test users
$ownerEmail = 'test-item-owner-' . uniqid() . '@test.cz';
$otherEmail = 'test-item-other-' . uniqid() . '@test.cz';

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

// Create test project + membership
$project = $projectRepo->insert([
	'name' => 'Item Presenter Test',
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
	'name' => 'Test Item Category',
	'status' => 'in_progress',
	'project_id' => $projectId,
	'parent_id' => null,
	'display_order' => 0,
	'created_at' => new DateTime(),
]);
$categoryId = $category->id;

// Create test item
$item = $itemRepo->insert([
	'description' => 'Test item popis',
	'amount' => '1500.50',
	'item_date' => '2026-02-20',
	'is_control_day' => 0,
	'include_in_construction_log' => 1,
	'weather' => 'Oblačno',
	'category_id' => $categoryId,
	'created_by_id' => $ownerId,
	'created_at' => new DateTime(),
]);
$itemId = $item->id;

register_shutdown_function(function () use ($db, $ownerId, $otherId, $projectId) {
	$db->query('DELETE FROM item WHERE category_id IN (SELECT id FROM category WHERE project_id = ?)', $projectId);
	$db->table('category')->where('project_id', $projectId)->delete();
	$db->table('project_member')->where('project_id', $projectId)->delete();
	$db->table('project')->where('id', $projectId)->delete();
	$db->table('profile')->where('user_id', $ownerId)->delete();
	$db->table('user')->where('id', $ownerId)->delete();
	$db->table('profile')->where('user_id', $otherId)->delete();
	$db->table('user')->where('id', $otherId)->delete();
});


/**
 * Run Item presenter action without auth.
 */
function runItem(string $action, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Item');
	$presenter->autoCanonicalize = false;

	$request = new AppRequest(
		'Item',
		'GET',
		array_merge(['action' => $action], $params),
	);
	return $presenter->run($request);
}


/**
 * Run Item presenter action as logged-in user.
 */
function runItemAs(int $userId, string $email, string $action, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Item');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$userId,
		['ROLE_USER'],
		['email' => $email, 'displayName' => $email],
	));

	$request = new AppRequest(
		'Item',
		'GET',
		array_merge(['action' => $action], $params),
	);
	return $presenter->run($request);
}


// === Auth guard tests ===

test('actionCreate redirects to login when not authenticated', function () use ($categoryId) {
	$response = runItem('create', ['categoryId' => $categoryId]);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});


test('actionEdit redirects to login when not authenticated', function () use ($itemId) {
	$response = runItem('edit', ['id' => $itemId]);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});


// === Access control tests ===

test('actionCreate returns 403 for non-member', function () use ($otherId, $otherEmail, $categoryId) {
	Assert::exception(
		fn() => runItemAs($otherId, $otherEmail, 'create', ['categoryId' => $categoryId]),
		Nette\Application\BadRequestException::class,
		null,
		403,
	);
});


test('actionEdit returns 403 for non-owner', function () use ($otherId, $otherEmail, $itemId, $projectId, $memberRepo, $db) {
	// Add other user as viewer (not owner)
	$db->table('project_member')->insert([
		'project_id' => $projectId,
		'user_id' => $otherId,
		'roles' => '["viewer"]',
		'has_global_category_access' => 1,
	]);

	Assert::exception(
		fn() => runItemAs($otherId, $otherEmail, 'edit', ['id' => $itemId]),
		Nette\Application\BadRequestException::class,
		null,
		403,
	);

	// Clean up membership
	$memberRepo->findByProjectAndUser($projectId, $otherId)?->delete();
});


// === 404 tests ===

test('actionCreate returns 404 for nonexistent category', function () use ($ownerId, $ownerEmail) {
	Assert::exception(
		fn() => runItemAs($ownerId, $ownerEmail, 'create', ['categoryId' => 999999]),
		Nette\Application\BadRequestException::class,
		null,
		404,
	);
});


test('actionEdit returns 404 for nonexistent item', function () use ($ownerId, $ownerEmail) {
	Assert::exception(
		fn() => runItemAs($ownerId, $ownerEmail, 'edit', ['id' => 999999]),
		Nette\Application\BadRequestException::class,
		null,
		404,
	);
});


// === Rendering tests ===

test('actionCreate renders form for owner', function () use ($ownerId, $ownerEmail, $categoryId) {
	$response = runItemAs($ownerId, $ownerEmail, 'create', ['categoryId' => $categoryId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('name="description"', $html);
	Assert::contains('name="itemDate"', $html);
	Assert::contains('name="weather"', $html);
	Assert::contains('name="amount"', $html);
	Assert::contains('name="isControlDay"', $html);
	Assert::contains('name="includeInConstructionLog"', $html);
});


test('actionEdit renders form with defaults for owner', function () use ($ownerId, $ownerEmail, $itemId) {
	$response = runItemAs($ownerId, $ownerEmail, 'edit', ['id' => $itemId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('name="description"', $html);
	Assert::contains('Test item popis', $html);
	Assert::contains('2026-02-20', $html);
});


// === Form structure tests ===

test('itemForm has all fields for owner', function () use ($presenterFactory, $ownerId, $ownerEmail, $categoryId) {
	$presenter = $presenterFactory->createPresenter('Item');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId, ['ROLE_USER'], ['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Item', 'GET', ['action' => 'create', 'categoryId' => $categoryId]);
	$presenter->run($request);

	$form = $presenter['itemForm'];

	Assert::type(Nette\Application\UI\Form::class, $form);
	Assert::true(isset($form['description']));
	Assert::true(isset($form['itemDate']));
	Assert::true(isset($form['weather']));
	Assert::true(isset($form['amount']));
	Assert::true(isset($form['isControlDay']));
	Assert::true(isset($form['includeInConstructionLog']));
	Assert::true(isset($form['send']));
});


test('itemForm hides amount and flags for viewer', function () use ($presenterFactory, $otherId, $otherEmail, $categoryId, $projectId, $db, $memberRepo) {
	// Add other user as viewer
	$db->table('project_member')->insert([
		'project_id' => $projectId,
		'user_id' => $otherId,
		'roles' => '["viewer"]',
		'has_global_category_access' => 1,
	]);

	$presenter = $presenterFactory->createPresenter('Item');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$otherId, ['ROLE_USER'], ['email' => $otherEmail, 'displayName' => $otherEmail],
	));

	$request = new AppRequest('Item', 'GET', ['action' => 'create', 'categoryId' => $categoryId]);
	$presenter->run($request);

	$form = $presenter['itemForm'];

	Assert::true(isset($form['description']));
	Assert::true(isset($form['itemDate']));
	Assert::true(isset($form['weather']));
	Assert::false(isset($form['amount']));
	Assert::false(isset($form['isControlDay']));
	Assert::false(isset($form['includeInConstructionLog']));

	// Clean up membership
	$memberRepo->findByProjectAndUser($projectId, $otherId)?->delete();
});
