<?php

/**
 * Integration tests for ProjectPresenter.
 * Tests auth guard, page rendering, form structure, and owner-only access.
 */

declare(strict_types=1);

use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ItemRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
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
/** @var ProjectRepository $projectRepo */
$projectRepo = $container->getByType(ProjectRepository::class);
/** @var ProjectMemberRepository $memberRepo */
$memberRepo = $container->getByType(ProjectMemberRepository::class);
/** @var UserRepository $userRepo */
$userRepo = $container->getByType(UserRepository::class);
/** @var Passwords $passwords */
$passwords = $container->getByType(Passwords::class);
/** @var CategoryRepository $catRepo */
$catRepo = $container->getByType(CategoryRepository::class);
/** @var ItemRepository $itemRepo */
$itemRepo = $container->getByType(ItemRepository::class);
/** @var Explorer $db */
$db = $container->getByType(Explorer::class);

// Create test users
$ownerEmail = 'test-proj-owner-' . uniqid() . '@test.cz';
$otherEmail = 'test-proj-other-' . uniqid() . '@test.cz';

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
	'name' => 'Presenter Test Projekt',
	'description' => 'Popis projektu',
	'address' => 'Testovací ulice 1',
	'status' => 'active',
	'currency' => 'CZK',
	'is_public' => 0,
	'owner_id' => $ownerId,
	'estimated_amount_cents' => 100000,
	'created_at' => new DateTime(),
]);
$projectId = $project->id;

// Create owner membership
$memberRepo->createOwner($projectId, $ownerId);

register_shutdown_function(function () use ($db, $ownerId, $otherId, $projectId) {
	$db->query('DELETE FROM item WHERE category_id IN (SELECT id FROM category WHERE project_id = ?)', $projectId);
	$db->table('category')->where('project_id', $projectId)->delete();
	$db->table('project_member')->where('project_id', $projectId)->delete();
	$db->table('project')->where('owner_id', $ownerId)->delete();
	$db->table('profile')->where('user_id', $ownerId)->delete();
	$db->table('user')->where('id', $ownerId)->delete();
	$db->table('profile')->where('user_id', $otherId)->delete();
	$db->table('user')->where('id', $otherId)->delete();
});

/**
 * Run presenter action without auth.
 */
function runProject(string $action, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;

	$request = new AppRequest(
		'Project',
		'GET',
		array_merge(['action' => $action], $params),
	);

	return $presenter->run($request);
}

/**
 * Run presenter action as logged-in user.
 */
function runProjectAs(int $userId, string $email, string $action, array $params = []): Nette\Application\Response
{
	global $presenterFactory;
	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$userId,
		['ROLE_USER'],
		['email' => $email, 'displayName' => $email],
	));

	$request = new AppRequest(
		'Project',
		'GET',
		array_merge(['action' => $action], $params),
	);

	return $presenter->run($request);
}

// === Auth guard tests ===

test('actionDefault redirects to login when not authenticated', function () {
	$response = runProject('default');

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});

test('actionCreate redirects to login when not authenticated', function () {
	$response = runProject('create');

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});

test('actionShow redirects to login when not authenticated', function () use ($projectId) {
	$response = runProject('show', ['id' => $projectId]);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});

test('actionEdit redirects to login when not authenticated', function () use ($projectId) {
	$response = runProject('edit', ['id' => $projectId]);

	Assert::type(RedirectResponse::class, $response);
	Assert::contains('prihlaseni', $response->getUrl());
});

// === Rendering tests ===

test('actionDefault renders project list for logged-in user', function () use ($ownerId, $ownerEmail) {
	$response = runProjectAs($ownerId, $ownerEmail, 'default');

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('Presenter Test Projekt', $html);
});

test('actionCreate renders form', function () use ($ownerId, $ownerEmail) {
	$response = runProjectAs($ownerId, $ownerEmail, 'create');

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('name="name"', $html);
	Assert::contains('name="description"', $html);
});

test('actionShow renders project detail for member', function () use ($ownerId, $ownerEmail, $projectId) {
	$response = runProjectAs($ownerId, $ownerEmail, 'show', ['id' => $projectId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('Presenter Test Projekt', $html);
	Assert::contains('Testovací ulice 1', $html);
});

test('actionShow returns 403 for non-member', function () use ($otherId, $otherEmail, $projectId) {
	Assert::exception(
		fn() => runProjectAs($otherId, $otherEmail, 'show', ['id' => $projectId]),
		Nette\Application\BadRequestException::class,
		null,
		403,
	);
});

test('actionShow returns 404 for nonexistent project', function () use ($ownerId, $ownerEmail) {
	Assert::exception(
		fn() => runProjectAs($ownerId, $ownerEmail, 'show', ['id' => 999999]),
		Nette\Application\BadRequestException::class,
		null,
		404,
	);
});

test('actionEdit renders for owner', function () use ($ownerId, $ownerEmail, $projectId) {
	$response = runProjectAs($ownerId, $ownerEmail, 'edit', ['id' => $projectId]);

	Assert::type(TextResponse::class, $response);
	$html = (string) $response->getSource();
	Assert::contains('name="name"', $html);
	Assert::contains('value="Presenter Test Projekt"', $html);
});

test('actionEdit returns 403 for non-owner', function () use ($otherId, $otherEmail, $projectId) {
	Assert::exception(
		fn() => runProjectAs($otherId, $otherEmail, 'edit', ['id' => $projectId]),
		Nette\Application\BadRequestException::class,
		null,
		403,
	);
});

// === Form structure tests ===

test('projectForm has correct structure', function () use ($presenterFactory, $ownerId, $ownerEmail) {
	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;

	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Project', 'GET', ['action' => 'create']);
	$presenter->run($request);

	$form = $presenter['projectForm'];

	Assert::type(Nette\Application\UI\Form::class, $form);
	Assert::true(isset($form['name']));
	Assert::true(isset($form['description']));
	Assert::true(isset($form['address']));
	Assert::true(isset($form['startDate']));
	Assert::true(isset($form['endDate']));
	Assert::true(isset($form['estimatedAmount']));
	Assert::true(isset($form['status']));
	Assert::true(isset($form['currency']));
	Assert::true(isset($form['isPublic']));
	Assert::true(isset($form['send']));
});

// === CategoryListControl signal tests ===

test('categoryList-delete signal deletes category and redirects', function () use ($catRepo, $projectId, $ownerId, $ownerEmail, $presenterFactory) {
	$tempCat = $catRepo->insert([
		'name' => 'Signal Delete',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 50,
		'created_at' => new DateTime(),
	]);

	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Project', 'GET', [
		'action' => 'show',
		'id' => $projectId,
		'do' => 'categoryList-delete',
		'categoryList-id' => $tempCat->id,
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);
	Assert::null($catRepo->findById($tempCat->id));
});

test('categoryList-changeStatus signal changes status and redirects', function () use ($catRepo, $projectId, $ownerId, $ownerEmail, $presenterFactory) {
	$cat = $catRepo->insert([
		'name' => 'Signal Status Change',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 51,
		'created_at' => new DateTime(),
	]);

	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Project', 'GET', [
		'action' => 'show',
		'id' => $projectId,
		'do' => 'categoryList-changeStatus',
		'categoryList-id' => $cat->id,
		'categoryList-status' => 'in_progress',
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);

	$updated = $catRepo->findById($cat->id);
	Assert::same('in_progress', $updated->status);
	Assert::notNull($updated->started_at);

	// Clean up
	$catRepo->delete($cat->id);
});

test('categoryList-changeStatus rejects invalid transition', function () use ($catRepo, $projectId, $ownerId, $ownerEmail, $presenterFactory) {
	$cat = $catRepo->insert([
		'name' => 'Signal Invalid Transition',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 52,
		'created_at' => new DateTime(),
	]);

	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Project', 'GET', [
		'action' => 'show',
		'id' => $projectId,
		'do' => 'categoryList-changeStatus',
		'categoryList-id' => $cat->id,
		'categoryList-status' => 'completed',
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);

	// Status should NOT have changed
	$unchanged = $catRepo->findById($cat->id);
	Assert::same('planned', $unchanged->status);

	// Clean up
	$catRepo->delete($cat->id);
});

test('categoryList-reorder signal returns redirect', function () use ($catRepo, $projectId, $ownerId, $ownerEmail, $presenterFactory) {
	$cat = $catRepo->insert([
		'name' => 'Signal Reorder',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 53,
		'created_at' => new DateTime(),
	]);

	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Project', 'GET', [
		'action' => 'show',
		'id' => $projectId,
		'do' => 'categoryList-reorder',
		'categoryList-id' => $cat->id,
		'categoryList-direction' => 'up',
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);

	// Clean up
	$catRepo->delete($cat->id);
});

test('categoryList-delete signal returns 403 for non-owner', function () use ($catRepo, $projectId, $otherId, $otherEmail, $ownerId, $ownerEmail, $presenterFactory, $memberRepo, $db) {
	$cat = $catRepo->insert([
		'name' => 'Signal Forbidden Delete',
		'status' => 'planned',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 54,
		'created_at' => new DateTime(),
	]);

	// Add other user as viewer (not owner)
	$db->table('project_member')->insert([
		'project_id' => $projectId,
		'user_id' => $otherId,
		'roles' => '["viewer"]',
		'has_global_category_access' => 1,
	]);

	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$otherId,
		['ROLE_USER'],
		['email' => $otherEmail, 'displayName' => $otherEmail],
	));

	Assert::exception(function () use ($presenter, $projectId, $cat) {
		$request = new AppRequest('Project', 'GET', [
			'action' => 'show',
			'id' => $projectId,
			'do' => 'categoryList-delete',
			'categoryList-id' => $cat->id,
		]);
		$presenter->run($request);
	}, Nette\Application\BadRequestException::class, null, 403);

	// Clean up
	$catRepo->delete($cat->id);
	$memberRepo->findByProjectAndUser($projectId, $otherId)?->delete();
});

// === CategoryListControl deleteItem signal tests ===

test('categoryList-deleteItem signal deletes item and redirects', function () use ($catRepo, $itemRepo, $projectId, $ownerId, $ownerEmail, $presenterFactory) {
	$cat = $catRepo->insert([
		'name' => 'Signal DeleteItem Cat',
		'status' => 'in_progress',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 60,
		'created_at' => new DateTime(),
	]);

	$item = $itemRepo->insert([
		'description' => 'Item to delete',
		'amount' => '500.00',
		'item_date' => '2026-02-20',
		'category_id' => $cat->id,
		'created_by_id' => $ownerId,
		'created_at' => new DateTime(),
	]);

	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$ownerId,
		['ROLE_USER'],
		['email' => $ownerEmail, 'displayName' => $ownerEmail],
	));

	$request = new AppRequest('Project', 'GET', [
		'action' => 'show',
		'id' => $projectId,
		'do' => 'categoryList-deleteItem',
		'categoryList-id' => $item->id,
	]);
	$response = $presenter->run($request);

	Assert::type(RedirectResponse::class, $response);
	Assert::null($itemRepo->findById($item->id));

	// Clean up
	$catRepo->delete($cat->id);
});

test('categoryList-deleteItem signal returns 403 for non-owner', function () use ($catRepo, $itemRepo, $projectId, $otherId, $otherEmail, $ownerId, $presenterFactory, $memberRepo, $db) {
	$cat = $catRepo->insert([
		'name' => 'Signal Forbidden DeleteItem Cat',
		'status' => 'in_progress',
		'project_id' => $projectId,
		'parent_id' => null,
		'display_order' => 61,
		'created_at' => new DateTime(),
	]);

	$item = $itemRepo->insert([
		'description' => 'Item forbidden delete',
		'item_date' => '2026-02-20',
		'category_id' => $cat->id,
		'created_by_id' => $ownerId,
		'created_at' => new DateTime(),
	]);

	// Add other user as viewer
	$db->table('project_member')->insert([
		'project_id' => $projectId,
		'user_id' => $otherId,
		'roles' => '["viewer"]',
		'has_global_category_access' => 1,
	]);

	$presenter = $presenterFactory->createPresenter('Project');
	$presenter->autoCanonicalize = false;
	$presenter->getUser()->login(new SimpleIdentity(
		$otherId,
		['ROLE_USER'],
		['email' => $otherEmail, 'displayName' => $otherEmail],
	));

	Assert::exception(function () use ($presenter, $projectId, $item) {
		$request = new AppRequest('Project', 'GET', [
			'action' => 'show',
			'id' => $projectId,
			'do' => 'categoryList-deleteItem',
			'categoryList-id' => $item->id,
		]);
		$presenter->run($request);
	}, Nette\Application\BadRequestException::class, null, 403);

	// Clean up
	$db->table('item')->where('id', $item->id)->delete();
	$catRepo->delete($cat->id);
	$memberRepo->findByProjectAndUser($projectId, $otherId)?->delete();
});
