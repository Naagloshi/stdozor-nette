<?php

declare(strict_types=1);

namespace App\Model\Service;

use App\Model\Repository\CategoryPermissionRepository;
use App\Model\Repository\ProjectInvitationRepository;
use App\Model\Repository\ProjectMemberRepository;
use App\Model\Security\MailService;
use Contributte\Translation\Translator;
use Nette\Database\Table\ActiveRow;

final class InvitationService
{
	private const TokenLength = 32; // bin2hex → 64 chars
	private const ExpiryDays = 7;

	public function __construct(
		private ProjectInvitationRepository $invitationRepository,
		private ProjectMemberRepository $memberRepository,
		private CategoryPermissionRepository $categoryPermissionRepository,
		private MailService $mailService,
		private Translator $translator,
	) {}

	/**
	 * Create a new invitation. Does NOT send the email — call sendInvitationEmail() after.
	 *
	 * @param string[] $roles
	 * @param int[] $categoryIds
	 *
	 * @throws \RuntimeException if active invitation already exists for this email+project
	 */
	public function createInvitation(
		int $projectId,
		string $email,
		array $roles,
		int $invitedByUserId,
		array $categoryIds,
	): ActiveRow {
		// Check for existing pending invitation
		$existing = $this->invitationRepository->findPendingByEmailAndProject($email, $projectId);
		if ($existing) {
			throw new \RuntimeException('project_member.error.active_invitation_exists');
		}

		$token = bin2hex(random_bytes(self::TokenLength));
		$expiresAt = new \DateTime('+' . self::ExpiryDays . ' days');

		return $this->invitationRepository->insert([
			'email' => $email,
			'roles' => json_encode(array_values($roles)),
			'token' => $token,
			'expires_at' => $expiresAt,
			'project_id' => $projectId,
			'invited_by_id' => $invitedByUserId,
			'category_ids' => json_encode(array_map('intval', $categoryIds)),
			'created_at' => new \DateTime(),
		]);
	}

	/**
	 * Send invitation email. Called by presenter which has access to link generation.
	 */
	public function sendInvitationEmail(ActiveRow $invitation, string $acceptUrl, string $projectName): void
	{
		$this->mailService->send(
			$invitation->email,
			$this->translator->translate('messages.project_member.invite.title') . ' — ' . $projectName,
			__DIR__ . '/../../Presenters/templates/emails/invitation.latte',
			[
				'projectName' => $projectName,
				'acceptUrl' => $acceptUrl,
				'expiresAt' => $invitation->expires_at,
			],
		);
	}

	/**
	 * Verify token validity. Returns invitation if valid, null otherwise.
	 */
	public function verifyToken(string $token): ?ActiveRow
	{
		$invitation = $this->invitationRepository->findByToken($token);
		if (!$invitation) {
			return null;
		}

		// Check validity: not used, not accepted, not expired
		if ($invitation->used || $invitation->accepted_at !== null) {
			return null;
		}

		$expiresAt = $invitation->expires_at instanceof \DateTimeInterface
			? $invitation->expires_at
			: new \DateTime($invitation->expires_at);

		if ($expiresAt < new \DateTime()) {
			return null;
		}

		return $invitation;
	}

	/**
	 * Accept invitation for a logged-in user.
	 * Creates new member or merges roles for existing member.
	 *
	 * @throws \RuntimeException if invitation is invalid
	 */
	public function acceptInvitation(ActiveRow $invitation, int $userId): ActiveRow
	{
		// Re-verify validity
		if ($invitation->used || $invitation->accepted_at !== null) {
			throw new \RuntimeException('project_member.error.invalid_invitation');
		}

		$projectId = $invitation->project_id;
		$roles = json_decode($invitation->roles, true) ?: [];
		$categoryIds = json_decode($invitation->category_ids, true) ?: [];
		$hasOwnerOrTDI = in_array('owner', $roles, true) || in_array('supervisor', $roles, true);

		// Check if user is already a member
		$existingMember = $this->memberRepository->findByProjectAndUser($projectId, $userId);

		if ($existingMember) {
			// Merge roles
			$this->memberRepository->addRoles($existingMember->id, $roles);

			// If becoming owner/TDI, upgrade to global access
			if ($hasOwnerOrTDI) {
				$this->memberRepository->updateGlobalAccess($existingMember->id, true);
			}

			// Add category permissions for non-owner/TDI roles
			if (!$hasOwnerOrTDI) {
				$this->addCategoryPermissions($existingMember->id, $categoryIds, $invitation->invited_by_id ?? $userId);
			}

			$member = $existingMember;
		} else {
			// Create new member
			$member = $this->memberRepository->createMember(
				$projectId,
				$userId,
				$roles,
				$hasOwnerOrTDI, // has_global_category_access
				$invitation->invited_by_id,
			);

			// Add category permissions for non-owner/TDI roles
			if (!$hasOwnerOrTDI) {
				$this->addCategoryPermissions($member->id, $categoryIds, $invitation->invited_by_id ?? $userId);
			}
		}

		// Mark invitation as used
		$this->invitationRepository->update($invitation->id, [
			'used' => 1,
			'accepted_at' => new \DateTime(),
			'project_member_id' => $member->id,
		]);

		return $member;
	}

	/**
	 * Cancel (delete) a pending invitation.
	 */
	public function cancelInvitation(int $invitationId): void
	{
		$this->invitationRepository->delete($invitationId);
	}

	/**
	 * Add category permissions from invitation.
	 *
	 * @param int[] $categoryIds
	 */
	private function addCategoryPermissions(int $memberId, array $categoryIds, int $grantedById): void
	{
		foreach ($categoryIds as $categoryId) {
			try {
				$this->categoryPermissionRepository->grantAccess(
					$memberId,
					(int) $categoryId,
					$grantedById,
				);
			} catch (\Nette\Database\UniqueConstraintViolationException) {
				// Permission already exists, skip
			}
		}
	}
}
