<?php

/**
 * Integration tests for AttachmentService.
 * Tests upload, validation, image resize, deletion, and bulk deletion.
 */

declare(strict_types=1);

use App\Model\Repository\AttachmentRepository;
use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ItemRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use App\Model\Repository\UserRepository;
use App\Model\Service\AttachmentService;
use Nette\Database\Explorer;
use Nette\Http\FileUpload;
use Nette\Security\Passwords;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$container = require __DIR__ . '/../bootstrap.container.php';

/** @var AttachmentService $attachmentService */
$attachmentService = $container->getByType(AttachmentService::class);
/** @var AttachmentRepository $attachmentRepo */
$attachmentRepo = $container->getByType(AttachmentRepository::class);
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

// Create test user
$email = 'test-attach-' . uniqid() . '@test.cz';
$user = $userRepo->insert([
	'email' => $email,
	'password' => $passwords->hash('TestPassword123456'),
	'roles' => '["ROLE_USER"]',
	'is_verified' => 1,
	'created_at' => new DateTime(),
]);
$userId = $user->id;

// Create test project + membership
$project = $projectRepo->insert([
	'name' => 'Attachment Test Project',
	'status' => 'active',
	'currency' => 'CZK',
	'is_public' => 0,
	'owner_id' => $userId,
	'created_at' => new DateTime(),
]);
$projectId = $project->id;
$memberRepo->createOwner($projectId, $userId);

// Create test category
$category = $catRepo->insert([
	'name' => 'Attachment Test Category',
	'status' => 'in_progress',
	'project_id' => $projectId,
	'parent_id' => null,
	'display_order' => 0,
	'created_at' => new DateTime(),
]);
$categoryId = $category->id;

// Create test item
$item = $itemRepo->insert([
	'description' => 'Attachment test item',
	'item_date' => '2026-02-21',
	'category_id' => $categoryId,
	'created_by_id' => $userId,
	'created_at' => new DateTime(),
]);
$itemId = $item->id;

// Temp directory for test files
$testTempDir = __DIR__ . '/../temp/test-attachments-' . uniqid();
FileSystem::createDir($testTempDir);

// Upload directory (from container config)
$uploadDir = $container->getParameters()['wwwDir'] . '/uploads';

register_shutdown_function(function () use ($db, $userId, $projectId, $testTempDir, $uploadDir) {
	$db->query('DELETE FROM attachment WHERE item_id IN (SELECT id FROM item WHERE category_id IN (SELECT id FROM category WHERE project_id = ?))', $projectId);
	$db->query('DELETE FROM item WHERE category_id IN (SELECT id FROM category WHERE project_id = ?)', $projectId);
	$db->table('category')->where('project_id', $projectId)->delete();
	$db->table('project_member')->where('project_id', $projectId)->delete();
	$db->table('project')->where('id', $projectId)->delete();
	$db->table('profile')->where('user_id', $userId)->delete();
	$db->table('user')->where('id', $userId)->delete();

	// Clean up test temp files
	if (is_dir($testTempDir)) {
		FileSystem::delete($testTempDir);
	}

	// Clean up uploaded files for this project
	$projectUploadDir = $uploadDir . '/' . $projectId;
	if (is_dir($projectUploadDir)) {
		FileSystem::delete($projectUploadDir);
	}
});


/**
 * Helper: create a temporary file for FileUpload testing.
 */
function createTempFile(string $dir, string $name, string $content, ?string $mimeType = null): FileUpload
{
	$path = $dir . '/' . $name;
	file_put_contents($path, $content);

	return new FileUpload([
		'name' => $name,
		'full_path' => $name,
		'size' => strlen($content),
		'tmp_name' => $path,
		'error' => UPLOAD_ERR_OK,
	]);
}


/**
 * Helper: create a minimal valid JPEG file.
 */
function createTestJpeg(string $dir, string $name = 'test.jpg', int $width = 100, int $height = 80): FileUpload
{
	$path = $dir . '/' . $name;
	$image = imagecreatetruecolor($width, $height);
	imagejpeg($image, $path, 90);
	imagedestroy($image);

	return new FileUpload([
		'name' => $name,
		'full_path' => $name,
		'size' => filesize($path),
		'tmp_name' => $path,
		'error' => UPLOAD_ERR_OK,
	]);
}


// === Validation tests ===

test('upload rejects file exceeding max size', function () use ($attachmentService, $testTempDir, $itemId, $categoryId, $projectId, $userId) {
	// Create a file > 10 MB (just check the constant works)
	$bigContent = str_repeat('x', 10 * 1024 * 1024 + 1);
	$file = createTempFile($testTempDir, 'big.pdf', $bigContent);

	Assert::exception(
		fn() => $attachmentService->uploadAttachment($file, $itemId, $categoryId, $projectId, $userId),
		RuntimeException::class,
		'File is too large. Maximum size is 10 MB.',
	);

	// Clean up the large file
	@unlink($testTempDir . '/big.pdf');
});


test('upload rejects invalid MIME type', function () use ($attachmentService, $testTempDir, $itemId, $categoryId, $projectId, $userId) {
	$file = createTempFile($testTempDir, 'script.sh', '#!/bin/bash\necho hello');

	Assert::exception(
		fn() => $attachmentService->uploadAttachment($file, $itemId, $categoryId, $projectId, $userId),
		RuntimeException::class,
		'Unsupported file type.',
	);
});


test('upload rejects when max attachments reached', function () use ($attachmentService, $attachmentRepo, $db, $testTempDir, $itemId, $categoryId, $projectId, $userId) {
	// Insert 10 fake attachment records to simulate max count
	for ($i = 0; $i < 10; $i++) {
		$db->table('attachment')->insert([
			'filename' => "fake-$i.jpg",
			'file_path' => "/tmp/fake-$i.jpg",
			'file_size' => 1024,
			'mime_type' => 'image/jpeg',
			'uploaded_at' => new DateTime(),
			'item_id' => $itemId,
			'uploaded_by_id' => $userId,
		]);
	}

	$file = createTestJpeg($testTempDir, 'eleventh.jpg');

	Assert::exception(
		fn() => $attachmentService->uploadAttachment($file, $itemId, $categoryId, $projectId, $userId),
		RuntimeException::class,
		'Maximum number of attachments reached (10).',
	);

	// Clean up fake records
	$db->table('attachment')->where('item_id', $itemId)->delete();
});


test('upload rejects failed upload (error code)', function () use ($attachmentService, $itemId, $categoryId, $projectId, $userId) {
	$file = new FileUpload([
		'name' => 'test.jpg',
		'full_path' => 'test.jpg',
		'size' => 100,
		'tmp_name' => '',
		'error' => UPLOAD_ERR_PARTIAL,
	]);

	Assert::exception(
		fn() => $attachmentService->uploadAttachment($file, $itemId, $categoryId, $projectId, $userId),
		RuntimeException::class,
		'Upload failed.',
	);
});


// === Successful upload tests ===

test('successful JPEG upload creates DB record and file', function () use ($attachmentService, $attachmentRepo, $testTempDir, $itemId, $categoryId, $projectId, $userId, $uploadDir) {
	$file = createTestJpeg($testTempDir, 'photo.jpg', 200, 150);

	$attachment = $attachmentService->uploadAttachment($file, $itemId, $categoryId, $projectId, $userId);

	// DB record created
	Assert::type(Nette\Database\Table\ActiveRow::class, $attachment);
	Assert::same('photo.jpg', $attachment->filename);
	Assert::same('image/jpeg', $attachment->mime_type);
	Assert::same($itemId, $attachment->item_id);
	Assert::same($userId, $attachment->uploaded_by_id);
	Assert::true($attachment->file_size > 0);
	Assert::true($attachment->image_width > 0);
	Assert::true($attachment->image_height > 0);

	// Physical file exists
	Assert::true(is_file($attachment->file_path));

	// File is in expected directory
	$expectedDir = $uploadDir . '/' . $projectId . '/' . $categoryId;
	Assert::true(str_starts_with($attachment->file_path, $expectedDir));

	// Clean up for next tests
	$attachmentService->deleteAttachment($attachment);
});


test('successful PDF upload creates DB record without image dimensions', function () use ($attachmentService, $testTempDir, $itemId, $categoryId, $projectId, $userId) {
	$file = createTempFile($testTempDir, 'document.pdf', '%PDF-1.4 fake pdf content');

	$attachment = $attachmentService->uploadAttachment($file, $itemId, $categoryId, $projectId, $userId);

	Assert::same('document.pdf', $attachment->filename);
	Assert::same($itemId, $attachment->item_id);
	Assert::null($attachment->image_width);
	Assert::null($attachment->image_height);
	Assert::true(is_file($attachment->file_path));

	// Clean up
	$attachmentService->deleteAttachment($attachment);
});


test('large image gets resized to max dimensions', function () use ($attachmentService, $testTempDir, $itemId, $categoryId, $projectId, $userId) {
	// Create oversized image (3000x2000)
	$file = createTestJpeg($testTempDir, 'large.jpg', 3000, 2000);

	$attachment = $attachmentService->uploadAttachment($file, $itemId, $categoryId, $projectId, $userId);

	// After resize, dimensions should be within limits (1920x1080, keeping aspect ratio)
	Assert::true($attachment->image_width <= 1920);
	Assert::true($attachment->image_height <= 1080);

	// Clean up
	$attachmentService->deleteAttachment($attachment);
});


// === Delete tests ===

test('deleteAttachment removes file and DB record', function () use ($attachmentService, $attachmentRepo, $testTempDir, $itemId, $categoryId, $projectId, $userId) {
	$file = createTestJpeg($testTempDir, 'to-delete.jpg');
	$attachment = $attachmentService->uploadAttachment($file, $itemId, $categoryId, $projectId, $userId);
	$filePath = $attachment->file_path;
	$attachmentId = $attachment->id;

	Assert::true(is_file($filePath));

	$attachmentService->deleteAttachment($attachment);

	// File removed
	Assert::false(is_file($filePath));

	// DB record removed
	$found = $attachmentRepo->findById($attachmentId);
	Assert::null($found);
});


test('deleteAllForItem removes all attachments for item', function () use ($attachmentService, $attachmentRepo, $testTempDir, $itemId, $categoryId, $projectId, $userId) {
	// Upload 3 files
	$paths = [];
	for ($i = 0; $i < 3; $i++) {
		$file = createTestJpeg($testTempDir, "bulk-$i.jpg");
		$att = $attachmentService->uploadAttachment($file, $itemId, $categoryId, $projectId, $userId);
		$paths[] = $att->file_path;
	}

	// Verify all exist
	foreach ($paths as $p) {
		Assert::true(is_file($p));
	}
	Assert::same(3, $attachmentRepo->countByItem($itemId));

	// Delete all
	$attachmentService->deleteAllForItem($itemId);

	// All files removed
	foreach ($paths as $p) {
		Assert::false(is_file($p));
	}

	// All DB records removed
	Assert::same(0, $attachmentRepo->countByItem($itemId));
});


// === Helper method tests ===

test('isImage returns true for image MIME types', function () use ($attachmentService) {
	Assert::true($attachmentService->isImage('image/jpeg'));
	Assert::true($attachmentService->isImage('image/png'));
	Assert::true($attachmentService->isImage('image/gif'));
	Assert::true($attachmentService->isImage('image/webp'));
	Assert::false($attachmentService->isImage('application/pdf'));
	Assert::false($attachmentService->isImage('application/zip'));
});


test('humanReadableSize formats sizes correctly', function () {
	Assert::same('0 B', AttachmentService::humanReadableSize(0));
	Assert::same('500 B', AttachmentService::humanReadableSize(500));
	Assert::same('1 KB', AttachmentService::humanReadableSize(1024));
	Assert::same('1.5 KB', AttachmentService::humanReadableSize(1536));
	Assert::same('1 MB', AttachmentService::humanReadableSize(1_048_576));
	Assert::same('2.5 MB', AttachmentService::humanReadableSize(2_621_440));
});
