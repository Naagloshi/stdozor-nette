<?php

/**
 * Integration tests for CategoryPresenter.
 * Tests auth guards, page rendering, form structure, and action handlers.
 */

declare(strict_types=1);

use App\Model\Repository\CategoryRepository;
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
$ownerEmail = 'test-cat-owner-' . uniqid() . '@test.cz';
$otherEmail = 'test-cat-other-' . uniqid() . '@test.cz';

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

// Create test project
$project = $projectRepo->insert([
	'name' => 'Cat Presenter Test',
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
	'name' => 'Test Category',
	'description' => 'Popis kategorie',
	'status' => 'planned',
	'project_id' => $projectId,
	'parent_id' => null,
	'display_order' => 0,
	'estimated_amount' => '50000.00',
	'created_at' => new DateTime(),
]);
$categoryId = $category->id;

register_shutdown_function(function () use ($db, $ownerId, $otherId, $projectId) {
	$db->table('category')->where('project_id', $projectId)->delete();
	$db->table('project')->where('id', $projectId)->delete();
	$db->table('profile')->where('user_id', $ownerId)->delete();
	$db->table('user')->where('id', $ownerId)->delete();
	$db->table('profile')->where('user_id', $otherId)->delete();
	$db->table('user')->where('id', $otherId)->delete();
});


/**
 * Run Category presenter action without auth.
 */
function runCategory(string $action, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Category');
	$presenter->autoCanonicalize = false;

	$request = new AppRequest(
		'Category',
		'GET',
		array_merge(['action' => $action], $params),
	);
	return $presenter->run($request);
}


/**
 * Run Category presenter action as logged-in user.
 */
function runCategoryAs(int $userId, string $email, string $action, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Category');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$userId,
		['ROLE_USER'],
		['email' => $email, 'displayName' => $email],
	));

	$request = new AppRequest(
		'Category',
		'GET',
		array_merge(['action' => $action], $params),
	);
	return $presenter->run($request);
}


// === Auth guard tests ===

test('actionCreate redirects to login when not authenticated', function () use ($projectId) {
	$response = runCategory('create', ['projectId' => $projectId]);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});


test('actionEdit redirects to login when not authenticated', function () use ($categoryId) {
	$response = runCategory('edit', ['id' => $categoryId]);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});


// === Access control tests ===

test('actionCreate returns 403 for non-owner', function () use ($otherId, $otherEmail, $projectId) {
	Assert::exception(
		fn() => runCategoryAs($otherId, $otherEmail, 'create', ['projectId' => $projectId]),
		Nette\Application\BadRequestException::class,
		null,
		403,
	);
});


test('actionEdit returns 403 for non-owner', function () use ($otherId, $otherEmail, $categoryId) {
	Assert::exception(
		fn() => runCategoryAs($otherId, $otherEmail, 'edit', ['id' => $categoryId]),
		Nette\Application\BadRequestException::class,
		null,
		403,
	);
});


// === 404 tests ===

test('actionEdit returns 404 for nonexistent category', function () use ($ownerId, $ownerEmail) {
	Assert::exception(
		fn() => runCategoryAs($ownerId, $ownerEmail, 'edit', ['id' => 999999]),
		Nette\Application\BadRequestException::class,
		null,
		404,
	);
});


test('actionCreate returns 404 for nonexistent project', function () use ($ownerId, $ownerEmail) {
	Assert::exception(
		fn() => runCategoryAs($ownerId, $ownerEmail, 'create', ['projectId' => 999999]),
		Nette\Application\BadRequestException::class,
		null,
		404,
	);
});


// === Rendering tests ===

test('actionCreate renders form for owner', function () use ($ownerId, $ownerEmail, $projectId) {
	$response = runCategoryAs($ownerId, $ownerEmail, 'create', ['projectId' => $projectId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('name="name"', $html);
	Assert::contains('name="description"', $html);
	Assert::contains('name="status"', $html);
});


test('actionCreate with parentId renders form', function () use ($ownerId, $ownerEmail, $projectId, $categoryId) {
	$response = runCategoryAs($ownerId, $ownerEmail, 'create', ['projectId' => $projectId, 'parentId' => $categoryId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('name="name"', $html);
	Assert::contains('Test Category', $html); // parent category name in breadcrumb
});


test('actionEdit renders form with defaults for owner', function () use ($ownerId, $ownerEmail, $categoryId) {
	$response = runCategoryAs($ownerId, $ownerEmail, 'edit', ['id' => $categoryId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('name="name"', $html);
	Assert::contains('value="Test Category"', $html);
});


// === Form structure tests ===

test('categoryForm has correct structure', function () use ($presenterFactory, $ownerId, $ownerEmail, $projectId) {
	$presenter = $presenterFactory->createPresenter('Category');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId, ['ROLE_USER'], ['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Category', 'GET', ['action' => 'create', 'projectId' => $projectId]);
	$presenter->run($request);

	$form = $presenter['categoryForm'];

	Assert::type(Nette\Application\UI\Form::class, $form);
	Assert::true(isset($form['name']));
	Assert::true(isset($form['description']));
	Assert::true(isset($form['status']));
	Assert::true(isset($form['estimatedAmount']));
	Assert::true(isset($form['manualAmountOverride']));
	Assert::true(isset($form['actualAmount']));
	Assert::true(isset($form['send']));
});


// === Signal handler tests moved to ProjectPresenterTest.phpt ===
// (delete, reorder, changeStatus are now signals on CategoryListControl component)
