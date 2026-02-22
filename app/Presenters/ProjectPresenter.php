<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Controls\CategoryList\CategoryListControl;
use App\Controls\CategoryList\ICategoryListControlFactory;
use App\Model\Enum\ProjectStatus;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use Contributte\Translation\Translator;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

final class ProjectPresenter extends BasePresenter
{
	private ?ActiveRow $project = null;

	private ?ActiveRow $membership = null;

	public function __construct(
		private ProjectRepository $projectRepository,
		private ProjectMemberRepository $memberRepository,
		private ICategoryListControlFactory $categoryListFactory,
		private Translator $translator,
	) {}

	public function actionDefault(): void
	{
		$this->requireLogin();
	}

	public function renderDefault(): void
	{
		$this->template->projects = $this->projectRepository->findByUser(
			$this->getUser()->getId(),
		);
	}

	public function actionCreate(): void
	{
		$this->requireLogin();
	}

	public function actionShow(int $id): void
	{
		$this->requireLogin();

		$this->project = $this->projectRepository->findById($id);
		if (!$this->project) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$this->membership = $this->memberRepository->findByProjectAndUser(
			$id,
			$this->getUser()->getId(),
		);
		if (!$this->membership) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}
	}

	public function renderShow(int $id): void
	{
		// Reload project for fresh amounts after signal handlers (delete, reorder, etc.)
		$this->project = $this->projectRepository->findById($id);

		$roles = $this->memberRepository->getRoles($this->membership);
		$status = ProjectStatus::tryFrom($this->project->status);

		$this->template->project = $this->project;
		$this->template->membership = $this->membership;
		$this->template->memberRoles = $roles;
		$this->template->isOwner = in_array('owner', $roles, true);
		$this->template->status = $status;
	}

	public function actionEdit(int $id): void
	{
		$this->requireLogin();

		$this->project = $this->projectRepository->findById($id);
		if (!$this->project) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		if (!$this->memberRepository->isOwner($id, $this->getUser()->getId())) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		// Pre-fill form with existing data
		$form = $this->getComponent('projectForm');
		$form->setDefaults([
			'name' => $this->project->name,
			'description' => $this->project->description,
			'address' => $this->project->address,
			'startDate' => $this->project->start_date?->format('Y-m-d'),
			'endDate' => $this->project->end_date?->format('Y-m-d'),
			'estimatedAmount' => $this->project->estimated_amount_cents !== null
				? $this->project->estimated_amount_cents / 100
				: null,
			'status' => $this->project->status,
			'currency' => $this->project->currency,
			'isPublic' => (bool) $this->project->is_public,
		]);

		// Lock currency if project has recorded amounts
		if ($this->project->total_amount_cents > 0) {
			/** @var \Nette\Forms\Controls\BaseControl $currencyControl */
			$currencyControl = $form['currency'];
			$currencyControl->setDisabled();
		}
	}

	public function renderEdit(int $id): void
	{
		$this->template->project = $this->project;
	}

	protected function createComponentCategoryList(): CategoryListControl
	{
		$roles = $this->memberRepository->getRoles($this->membership);

		return $this->categoryListFactory->create(
			$this->project->id,
			in_array('owner', $roles, true),
			$roles,
		);
	}

	public function handleDelete(int $id): void
	{
		$this->requireLogin();

		$project = $this->projectRepository->findById($id);
		if (!$project) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		if (!$this->memberRepository->isOwner($id, $this->getUser()->getId())) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$this->projectRepository->delete($id);
		$this->flashMessage(
			$this->translator->translate('messages.project.flash.deleted'),
			'success',
		);
		$this->redirect('default');
	}

	protected function createComponentProjectForm(): Form
	{
		$form = new Form();
		$form->addProtection();

		$form->addText('name', $this->translator->translate('messages.project.form.name'))
			->setRequired()
			->setMaxLength(200);

		$form->addTextArea('description', $this->translator->translate('messages.project.form.description'))
			->setHtmlAttribute('rows', 4);

		$form->addText('address', $this->translator->translate('messages.project.form.address'))
			->setMaxLength(255);

		$form->addText('startDate', $this->translator->translate('messages.project.form.start_date'))
			->setHtmlType('date');

		$form->addText('endDate', $this->translator->translate('messages.project.form.end_date'))
			->setHtmlType('date');

		$form->addText('estimatedAmount', $this->translator->translate('messages.project.form.estimated_amount'))
			->setHtmlType('number')
			->setHtmlAttribute('step', '0.01')
			->setHtmlAttribute('min', '0');

		$form->addSelect('status', $this->translator->translate('messages.project.form.status'), ProjectStatus::formOptions())
			->setRequired()
			->setDefaultValue(ProjectStatus::Planning->value);

		$form->addSelect('currency', $this->translator->translate('messages.project.form.currency'), [
			'CZK' => 'CZK',
			'EUR' => 'EUR',
			'USD' => 'USD',
		])->setDefaultValue('CZK');

		$form->addCheckbox('isPublic', $this->translator->translate('messages.project.form.is_public'));

		$form->addSubmit('send');

		$form->onSuccess[] = $this->projectFormSucceeded(...);

		return $form;
	}

	private function projectFormSucceeded(Form $form, \stdClass $data): void
	{
		$values = [
			'name' => $data->name,
			'description' => $data->description ?: null,
			'address' => $data->address ?: null,
			'start_date' => $data->startDate ?: null,
			'end_date' => $data->endDate ?: null,
			'estimated_amount_cents' => $data->estimatedAmount
				? (int) round((float) $data->estimatedAmount * 100)
				: null,
			'status' => $data->status,
			'currency' => $data->currency ?? 'CZK',
			'is_public' => $data->isPublic ? 1 : 0,
			'updated_at' => new \DateTime(),
		];

		if ($this->project) {
			// Edit mode
			$this->projectRepository->update($this->project->id, $values);
			$this->flashMessage(
				$this->translator->translate('messages.project.flash.updated'),
				'success',
			);
			$this->redirect('show', $this->project->id);
		} else {
			// Create mode
			$values['owner_id'] = $this->getUser()->getId();
			$values['created_at'] = new \DateTime();
			unset($values['updated_at']);

			$project = $this->projectRepository->insert($values);
			$this->memberRepository->createOwner($project->id, $this->getUser()->getId());

			$this->flashMessage(
				$this->translator->translate('messages.project.flash.created'),
				'success',
			);
			$this->redirect('show', $project->id);
		}
	}
}
