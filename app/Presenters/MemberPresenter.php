<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Model\Enum\ProjectRole;
use App\Model\Repository\CategoryPermissionRepository;
use App\Model\Repository\CategoryRepository;
use App\Model\Repository\ProjectInvitationRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Repository\ProjectRepository;
use App\Model\Service\InvitationService;
use Contributte\Translation\Translator;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

final class MemberPresenter extends BasePresenter
{
	private ?ActiveRow $project = null;

	private ?ActiveRow $membership = null;

	private ?ActiveRow $targetMember = null;

	private bool $isOwner = false;

	public function __construct(
		private ProjectRepository $projectRepository,
		private ProjectMemberRepository $memberRepository,
		private ProjectInvitationRepository $invitationRepository,
		private CategoryRepository $categoryRepository,
		private CategoryPermissionRepository $categoryPermissionRepository,
		private InvitationService $invitationService,
		private Translator $translator,
	) {}

	// ---- Member list ----

	public function actionDefault(int $projectId): void
	{
		$this->requireLogin();

		$this->project = $this->projectRepository->findById($projectId);
		if (!$this->project) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$this->membership = $this->memberRepository->findByProjectAndUser(
			$projectId,
			$this->getUser()->getId(),
		);
		if (!$this->membership) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$roles = $this->memberRepository->getRoles($this->membership);
		$this->isOwner = in_array('owner', $roles, true);
	}

	public function renderDefault(int $projectId): void
	{
		$this->template->project = $this->project;
		$this->template->members = $this->memberRepository->findByProject($projectId);
		$this->template->isOwner = $this->isOwner;

		$this->template->pendingInvitations = $this->isOwner
			? $this->invitationRepository->findPendingByProject($projectId)
			: [];
	}

	// ---- Invite ----

	public function actionInvite(int $projectId): void
	{
		$this->requireLogin();

		$this->project = $this->projectRepository->findById($projectId);
		if (!$this->project) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		if (!$this->memberRepository->isOwner($projectId, $this->getUser()->getId())) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}
	}

	public function renderInvite(int $projectId): void
	{
		$this->template->project = $this->project;
	}

	protected function createComponentInviteForm(): Form
	{
		$form = new Form();
		$form->addProtection();

		$form->addEmail('email', $this->translator->translate('messages.project_member.form.email'))
			->setRequired();

		$roleOptions = [];
		foreach (ProjectRole::cases() as $role) {
			$roleOptions[$role->value] = $role->label();
		}

		$form->addCheckboxList('roles', $this->translator->translate('messages.project_member.form.roles'), $roleOptions)
			->setRequired($this->translator->translate('messages.project_member.form.roles_help'));

		$form->addCheckbox('hasGlobalCategoryAccess', $this->translator->translate('messages.project_member.form.has_global_category_access'));

		$rootCategories = $this->categoryRepository->findRootsByProject($this->project->id);
		$categoryOptions = [];
		foreach ($rootCategories as $cat) {
			$categoryOptions[$cat->id] = $cat->name;
		}

		$form->addCheckboxList('categories', $this->translator->translate('messages.project_member.form.categories'), $categoryOptions);

		$form->addSubmit('send', $this->translator->translate('messages.project_member.invite.submit'));

		$form->onSuccess[] = $this->inviteFormSucceeded(...);

		return $form;
	}

	private function inviteFormSucceeded(Form $form, \stdClass $data): void
	{
		$roles = $data->roles;
		$hasOwnerOrTDI = in_array('owner', $roles, true) || in_array('supervisor', $roles, true);

		if ($hasOwnerOrTDI) {
			$categoryIds = [];
		} elseif ($data->hasGlobalCategoryAccess) {
			$rootCategories = $this->categoryRepository->findRootsByProject($this->project->id);
			$categoryIds = array_map(fn($c) => $c->id, $rootCategories);
		} else {
			$categoryIds = $data->categories;
		}

		try {
			$invitation = $this->invitationService->createInvitation(
				$this->project->id,
				$data->email,
				$roles,
				$this->getUser()->getId(),
				$categoryIds,
			);

			$acceptUrl = $this->link('//Member:accept', ['token' => $invitation->token]);
			$this->invitationService->sendInvitationEmail($invitation, $acceptUrl, $this->project->name);

			$this->flashMessage(
				$this->translator->translate('messages.project_member.flash.invitation_sent'),
				'success',
			);
			$this->redirect('default', $this->project->id);
		} catch (\RuntimeException $e) {
			$this->flashMessage(
				$this->translator->translate('messages.' . $e->getMessage()),
				'error',
			);
			$this->redirect('this');
		}
	}

	// ---- Change roles ----

	public function actionChangeRoles(int $projectId, int $memberId): void
	{
		$this->requireLogin();

		$this->project = $this->projectRepository->findById($projectId);
		if (!$this->project) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		if (!$this->memberRepository->isOwner($projectId, $this->getUser()->getId())) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$this->targetMember = $this->memberRepository->findById($memberId);
		if (!$this->targetMember || $this->targetMember->project_id !== $projectId) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		// Cannot change own roles
		if ($this->targetMember->user_id === $this->getUser()->getId()) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		// Pre-fill form
		$currentRoles = $this->memberRepository->getRoles($this->targetMember);
		$currentCategoryIds = $this->categoryPermissionRepository->getCategoryIdsForMember($this->targetMember->id);

		$form = $this->getComponent('changeRolesForm');
		$form->setDefaults([
			'roles' => $currentRoles,
			'hasGlobalCategoryAccess' => (bool) $this->targetMember->has_global_category_access,
			'categories' => $currentCategoryIds,
		]);
	}

	public function renderChangeRoles(int $projectId, int $memberId): void
	{
		$this->template->project = $this->project;
		$this->template->targetMember = $this->targetMember;
	}

	protected function createComponentChangeRolesForm(): Form
	{
		$form = new Form();
		$form->addProtection();

		$roleOptions = [];
		foreach (ProjectRole::cases() as $role) {
			$roleOptions[$role->value] = $role->label();
		}

		$form->addCheckboxList('roles', $this->translator->translate('messages.project_member.form.roles'), $roleOptions)
			->setRequired($this->translator->translate('messages.project_member.form.roles_help'));

		$form->addCheckbox('hasGlobalCategoryAccess', $this->translator->translate('messages.project_member.form.has_global_category_access'));

		// Categories - loaded lazily via $this->project
		$categoryOptions = [];
		if ($this->project) {
			$rootCategories = $this->categoryRepository->findRootsByProject($this->project->id);
			foreach ($rootCategories as $cat) {
				$categoryOptions[$cat->id] = $cat->name;
			}
		}

		$form->addCheckboxList('categories', $this->translator->translate('messages.project_member.form.categories'), $categoryOptions);

		$form->addSubmit('send', $this->translator->translate('messages.project_member.change_roles.submit'));

		$form->onSuccess[] = $this->changeRolesFormSucceeded(...);

		return $form;
	}

	private function changeRolesFormSucceeded(Form $form, \stdClass $data): void
	{
		$roles = $data->roles;
		$hasOwnerOrTDI = in_array('owner', $roles, true) || in_array('supervisor', $roles, true);

		// Update roles
		$this->memberRepository->updateRoles($this->targetMember->id, $roles);

		if ($hasOwnerOrTDI) {
			// Owner/TDI have automatic access — clear explicit permissions
			$this->memberRepository->updateGlobalAccess($this->targetMember->id, false);
			$this->categoryPermissionRepository->revokeAllForMember($this->targetMember->id);
		} else {
			$this->memberRepository->updateGlobalAccess(
				$this->targetMember->id,
				$data->hasGlobalCategoryAccess,
			);

			// Revoke all and re-create
			$this->categoryPermissionRepository->revokeAllForMember($this->targetMember->id);

			if ($data->hasGlobalCategoryAccess) {
				$rootCategories = $this->categoryRepository->findRootsByProject($this->project->id);
				foreach ($rootCategories as $cat) {
					$this->categoryPermissionRepository->grantAccess(
						$this->targetMember->id,
						$cat->id,
						$this->getUser()->getId(),
					);
				}
			} else {
				foreach ($data->categories as $categoryId) {
					$this->categoryPermissionRepository->grantAccess(
						$this->targetMember->id,
						(int) $categoryId,
						$this->getUser()->getId(),
					);
				}
			}
		}

		$this->flashMessage(
			$this->translator->translate('messages.project_member.flash.roles_changed'),
			'success',
		);
		$this->redirect('default', $this->project->id);
	}

	// ---- Accept invitation ----

	public function actionAccept(string $token): void
	{
		$invitation = $this->invitationService->verifyToken($token);
		if (!$invitation) {
			$this->flashMessage(
				$this->translator->translate('messages.project_member.flash.invalid_invitation'),
				'error',
			);
			$this->redirect('Homepage:default');
		}

		// Not logged in — store token and redirect to login
		if (!$this->getUser()->isLoggedIn()) {
			$this->getSession('invitation')->token = $token;
			$this->flashMessage(
				$this->translator->translate('messages.project_member.flash.login_to_accept_invitation'),
				'info',
			);
			$this->redirect('Sign:in');
		}

		// Logged in — accept immediately
		try {
			$member = $this->invitationService->acceptInvitation($invitation, $this->getUser()->getId());

			// Check if roles were merged vs new member
			$existingBefore = $this->memberRepository->findByProjectAndUser(
				$invitation->project_id,
				$this->getUser()->getId(),
			);

			$this->flashMessage(
				$this->translator->translate('messages.project_member.flash.invitation_accepted'),
				'success',
			);
			$this->redirect('Project:show', $invitation->project_id);
		} catch (\RuntimeException $e) {
			$this->flashMessage(
				$this->translator->translate('messages.' . $e->getMessage()),
				'error',
			);
			$this->redirect('Homepage:default');
		}
	}

	// ---- Signals ----

	public function handleRemove(int $memberId): void
	{
		$this->requireLogin();

		$member = $this->memberRepository->findById($memberId);
		if (!$member) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$projectId = $member->project_id;

		// Must be owner
		if (!$this->memberRepository->isOwner($projectId, $this->getUser()->getId())) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		// Cannot remove self
		if ($member->user_id === $this->getUser()->getId()) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		// Cannot remove permanent owner
		if ($this->memberRepository->isPermanentOwner($projectId, $member->user_id)) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		// Cannot remove last owner
		$memberRoles = $this->memberRepository->getRoles($member);
		if (in_array('owner', $memberRoles, true) && $this->memberRepository->countOwners($projectId) <= 1) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		// Delete category permissions first, then member
		$this->categoryPermissionRepository->revokeAllForMember($memberId);
		$this->memberRepository->delete($memberId);

		$this->flashMessage(
			$this->translator->translate('messages.project_member.flash.member_removed'),
			'success',
		);
		$this->redirect('default', $projectId);
	}

	public function handleCancelInvitation(int $invitationId): void
	{
		$this->requireLogin();

		$invitation = $this->invitationRepository->findById($invitationId);
		if (!$invitation) {
			$this->error($this->translator->translate('messages.error.not_found'), 404);
		}

		$projectId = $invitation->project_id;

		// Must be owner
		if (!$this->memberRepository->isOwner($projectId, $this->getUser()->getId())) {
			$this->error($this->translator->translate('messages.error.forbidden'), 403);
		}

		$this->invitationService->cancelInvitation($invitationId);

		$this->flashMessage(
			$this->translator->translate('messages.project_member.flash.invitation_cancelled'),
			'success',
		);
		$this->redirect('default', $projectId);
	}
}
