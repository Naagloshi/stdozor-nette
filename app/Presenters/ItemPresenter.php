<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ItemRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use Contributte\Translation\Translator;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;


final class ItemPresenter extends BasePresenter
{
	private ?ActiveRow $project = null;
	private ?ActiveRow $category = null;
	private ?ActiveRow $item = null;
	private ?ActiveRow $membership = null;
	private bool $canEditAmount = false;
	private bool $canEditFlags = false;


	public function __construct(
		private ItemRepository $itemRepository,
		private CategoryRepository $categoryRepository,
		private ProjectRepository $projectRepository,
		private ProjectMemberRepository $memberRepository,
		private Translator $translator,
	) {
	}


	public function actionCreate(int $categoryId): void
	{
		$this->requireLogin();

		$this->category = $this->categoryRepository->findById($categoryId);
		if (!$this->category) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$this->project = $this->projectRepository->findById($this->category->project_id);
		if (!$this->project) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$this->membership = $this->memberRepository->findByProjectAndUser(
			$this->project->id,
			$this->getUser()->getId(),
		);
		if (!$this->membership) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$roles = $this->memberRepository->getRoles($this->membership);
		$isOwner = in_array('owner', $roles, true);

		// Only owner can add items to completed categories
		if ($this->category->status === 'completed' && !$isOwner) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$this->canEditAmount = $isOwner;
		$this->canEditFlags = $isOwner || in_array('supervisor', $roles, true);
	}


	public function renderCreate(int $categoryId): void
	{
		$this->template->project = $this->project;
		$this->template->category = $this->category;
		$this->template->canEditAmount = $this->canEditAmount;
		$this->template->canEditFlags = $this->canEditFlags;
	}


	public function actionEdit(int $id): void
	{
		$this->requireLogin();

		$this->item = $this->itemRepository->findById($id);
		if (!$this->item) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$this->category = $this->categoryRepository->findById($this->item->category_id);
		if (!$this->category) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$this->project = $this->projectRepository->findById($this->category->project_id);

		$this->membership = $this->memberRepository->findByProjectAndUser(
			$this->project->id,
			$this->getUser()->getId(),
		);
		if (!$this->membership) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$roles = $this->memberRepository->getRoles($this->membership);
		$isOwner = in_array('owner', $roles, true);

		// Only owner can edit items
		if (!$isOwner) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$this->canEditAmount = true;
		$this->canEditFlags = true;

		$form = $this->getComponent('itemForm');
		$form->setDefaults([
			'description' => $this->item->description,
			'itemDate' => $this->item->item_date instanceof \DateTimeInterface
				? $this->item->item_date->format('Y-m-d')
				: (string) $this->item->item_date,
			'weather' => $this->item->weather,
			'amount' => $this->item->amount,
			'isControlDay' => (bool) $this->item->is_control_day,
			'includeInConstructionLog' => (bool) $this->item->include_in_construction_log,
		]);
	}


	public function renderEdit(int $id): void
	{
		$this->template->project = $this->project;
		$this->template->category = $this->category;
		$this->template->item = $this->item;
		$this->template->canEditAmount = $this->canEditAmount;
		$this->template->canEditFlags = $this->canEditFlags;
	}


	protected function createComponentItemForm(): Form
	{
		$form = new Form;
		$form->addProtection();

		$form->addTextArea('description', $this->translator->translate('messages.item.form.description'))
			->setRequired()
			->setHtmlAttribute('rows', 5);

		$form->addText('itemDate', $this->translator->translate('messages.item.form.item_date'))
			->setHtmlType('date')
			->setRequired()
			->setDefaultValue(date('Y-m-d'));

		$form->addText('weather', $this->translator->translate('messages.item.form.weather'))
			->setMaxLength(500);

		if ($this->canEditAmount) {
			$form->addText('amount', $this->translator->translate('messages.item.form.amount'))
				->setHtmlType('number')
				->setHtmlAttribute('step', '0.01');
		}

		if ($this->canEditFlags) {
			$form->addCheckbox('isControlDay', $this->translator->translate('messages.item.form.is_control_day'));
			$form->addCheckbox('includeInConstructionLog', $this->translator->translate('messages.item.form.include_in_construction_log'));
		}

		$form->addSubmit('send');

		$form->onSuccess[] = $this->itemFormSucceeded(...);

		return $form;
	}


	private function itemFormSucceeded(Form $form, \stdClass $data): void
	{
		$values = [
			'description' => $data->description,
			'item_date' => $data->itemDate,
			'weather' => $data->weather ?: null,
		];

		if ($this->canEditAmount && isset($data->amount)) {
			$values['amount'] = $data->amount !== '' && $data->amount !== null
				? (string) $data->amount
				: null;
		}

		if ($this->canEditFlags) {
			$values['is_control_day'] = isset($data->isControlDay) && $data->isControlDay ? 1 : 0;
			$values['include_in_construction_log'] = isset($data->includeInConstructionLog) && $data->includeInConstructionLog ? 1 : 0;
		}

		if ($this->item) {
			// Edit mode
			$values['updated_at'] = new \DateTime();
			$this->itemRepository->update($this->item->id, $values);

			$this->flashMessage(
				$this->translator->translate('messages.item.flash.updated'),
				'success',
			);
		} else {
			// Create mode
			$values['category_id'] = $this->category->id;
			$values['created_by_id'] = $this->getUser()->getId();
			$values['created_at'] = new \DateTime();

			$this->itemRepository->insert($values);

			$this->flashMessage(
				$this->translator->translate('messages.item.flash.created'),
				'success',
			);
		}

		$this->redirect('Project:show', $this->project->id);
	}
}
