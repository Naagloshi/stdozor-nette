<?php

declare(strict_types=1);

namespace App\Model\Service;

use App\Model\Repository\AttachmentRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Http\FileUpload;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;


final class AttachmentService
{
	private const MaxFileSize = 10 * 1024 * 1024; // 10 MB
	private const MaxAttachmentsPerItem = 10;
	private const MaxImageWidth = 1920;
	private const MaxImageHeight = 1080;

	private const AllowedMimeTypes = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'application/pdf',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/zip',
		'application/x-zip-compressed',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.oasis.opendocument.presentation',
	];


	public function __construct(
		private AttachmentRepository $attachmentRepository,
		private string $uploadDirectory,
	) {
	}


	public function uploadAttachment(
		FileUpload $file,
		int $itemId,
		int $categoryId,
		int $projectId,
		int $userId,
	): ActiveRow
	{
		$this->validateUpload($file, $itemId);

		// Create storage directory
		$dir = $this->uploadDirectory . '/' . $projectId . '/' . $categoryId;
		FileSystem::createDir($dir);

		// Generate safe filename
		$extension = strtolower(pathinfo($file->getUntrustedName(), PATHINFO_EXTENSION));
		$safeFilename = bin2hex(random_bytes(16)) . '.' . $extension;
		$fullPath = $dir . '/' . $safeFilename;

		// Move file
		$file->move($fullPath);

		// Resize images and get dimensions
		$imageWidth = null;
		$imageHeight = null;
		$mimeType = $file->getContentType() ?? 'application/octet-stream';

		if ($this->isImage($mimeType)) {
			try {
				$image = Image::fromFile($fullPath);
				$image->resize(self::MaxImageWidth, self::MaxImageHeight, Image::ShrinkOnly);
				$image->save($fullPath);

				$dimensions = getimagesize($fullPath);
				if ($dimensions !== false) {
					$imageWidth = $dimensions[0];
					$imageHeight = $dimensions[1];
				}
			} catch (\Throwable) {
				// Image processing failed — keep original file
			}
		}

		// Get final file size (after potential resize)
		$fileSize = filesize($fullPath);

		return $this->attachmentRepository->insert([
			'filename' => $file->getUntrustedName(),
			'file_path' => $fullPath,
			'file_size' => $fileSize,
			'mime_type' => $mimeType,
			'image_width' => $imageWidth,
			'image_height' => $imageHeight,
			'uploaded_at' => new \DateTime(),
			'item_id' => $itemId,
			'uploaded_by_id' => $userId,
		]);
	}


	public function deleteAttachment(ActiveRow $attachment): void
	{
		$filePath = $attachment->file_path;

		// Delete physical file
		if (is_file($filePath)) {
			@unlink($filePath);
		}

		// Remove empty directories (up to 2 levels: categoryId, projectId)
		$dir = dirname($filePath);
		for ($i = 0; $i < 2; $i++) {
			if (is_dir($dir) && $this->isDirectoryEmpty($dir)) {
				@rmdir($dir);
				$dir = dirname($dir);
			} else {
				break;
			}
		}

		// Delete DB record
		$this->attachmentRepository->delete($attachment->id);
	}


	public function deleteAllForItem(int $itemId): void
	{
		$attachments = $this->attachmentRepository->findByItem($itemId);
		foreach ($attachments as $attachment) {
			$filePath = $attachment->file_path;
			if (is_file($filePath)) {
				@unlink($filePath);
			}
		}

		// Clean up empty directories
		if (!empty($attachments)) {
			$firstAttachment = reset($attachments);
			$dir = dirname($firstAttachment->file_path);
			for ($i = 0; $i < 2; $i++) {
				if (is_dir($dir) && $this->isDirectoryEmpty($dir)) {
					@rmdir($dir);
					$dir = dirname($dir);
				} else {
					break;
				}
			}
		}

		$this->attachmentRepository->deleteByItem($itemId);
	}


	public function isImage(string $mimeType): bool
	{
		return str_starts_with($mimeType, 'image/');
	}


	public static function humanReadableSize(int $bytes): string
	{
		if ($bytes < 1024) {
			return $bytes . ' B';
		}
		if ($bytes < 1_048_576) {
			return round($bytes / 1024, 1) . ' KB';
		}

		return round($bytes / 1_048_576, 1) . ' MB';
	}


	private function validateUpload(FileUpload $file, int $itemId): void
	{
		if (!$file->isOk()) {
			throw new \RuntimeException('Upload failed.');
		}

		if ($file->getSize() > self::MaxFileSize) {
			throw new \RuntimeException('File is too large. Maximum size is 10 MB.');
		}

		$mimeType = $file->getContentType() ?? '';
		if (!in_array($mimeType, self::AllowedMimeTypes, true)) {
			throw new \RuntimeException('Unsupported file type.');
		}

		$currentCount = $this->attachmentRepository->countByItem($itemId);
		if ($currentCount >= self::MaxAttachmentsPerItem) {
			throw new \RuntimeException('Maximum number of attachments reached (10).');
		}
	}


	private function isDirectoryEmpty(string $dir): bool
	{
		$files = @scandir($dir);
		if ($files === false) {
			return false;
		}

		return count($files) <= 2; // . and ..
	}
}
