<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Model\Repository\AttachmentRepository;
use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ItemRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use App\Model\Service\AttachmentService;
use Contributte\Translation\Translator;
use Nette\Application\Responses\FileResponse;


final class AttachmentPresenter extends BasePresenter
{
	public function __construct(
		private AttachmentRepository $attachmentRepository,
		private AttachmentService $attachmentService,
		private ItemRepository $itemRepository,
		private CategoryRepository $categoryRepository,
		private ProjectRepository $projectRepository,
		private ProjectMemberRepository $memberRepository,
		private Translator $translator,
	) {
	}


	public function actionDownload(int $id): void
	{
		$this->requireLogin();

		$attachment = $this->attachmentRepository->findById($id);
		if (!$attachment) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$item = $this->itemRepository->findById($attachment->item_id);
		if (!$item) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$category = $this->categoryRepository->findById($item->category_id);
		if (!$category) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$project = $this->projectRepository->findById($category->project_id);

		// Check membership
		$membership = $this->memberRepository->findByProjectAndUser(
			$project->id,
			$this->getUser()->getId(),
		);
		if (!$membership) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		// Non-image files: only owner or investor
		$isImage = $this->attachmentService->isImage($attachment->mime_type);
		if (!$isImage) {
			$roles = $this->memberRepository->getRoles($membership);
			if (!in_array('owner', $roles, true) && !in_array('investor', $roles, true)) {
				$this->error($this->translator->translate('messages.error.forbidden'), 403);
			}
		}

		$filePath = $attachment->file_path;
		if (!is_file($filePath)) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$this->sendResponse(new FileResponse(
			$filePath,
			$attachment->filename,
			$attachment->mime_type,
			forceDownload: !$isImage,
		));
	}
}
