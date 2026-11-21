<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

use InvalidArgumentException;
use OCA\SecretSanta\Db\Participant;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IEmailValidator;
use OCP\Share\IManager as IShareManager;

/**
 * @psalm-import-type SecretSantaParticipant from ResponseDefinitions
 */
final class ParticipantService {

	/** Maximum number of participants of a list, limits the number of emails sent by a draw */
	public const int MAX_PARTICIPANTS = 100;
	/** Maximum length of the name and email address of guests (length of the database columns) */
	public const int MAX_LENGTH = 255;

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IL10N $l10n,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IShareManager $shareManager,
		private IEmailValidator $emailValidator,
		private ParticipantMapper $participantMapper,
	) {
	}

	/**
	 * @return list<Participant>
	 */
	public function getParticipants(SecretSantaList $list): array {
		return $this->participantMapper->getParticipants($list);
	}

	/**
	 * @throws DoesNotExistException If the participant does not exist or is not part of the list
	 */
	public function get(SecretSantaList $list, string $id): Participant {
		return $this->participantMapper->getParticipant($list, $id);
	}

	/**
	 * Add a Nextcloud user to a list.
	 *
	 * Only users the owner of the list is allowed to share with can be added.
	 *
	 * @throws InvalidInputException If the user can not be added
	 */
	public function addUser(SecretSantaList $list, string $userId): Participant {
		$user = $this->userManager->get($userId);
		// Users the owner can not share with are reported as unknown to not reveal their existence
		if ($user === null || !$user->isEnabled() || !$this->canAddUser($list->getOwner(), $user)) {
			throw new InvalidInputException('Unknown user.', $this->l10n->t('The user does not exist.'));
		}

		$participants = $this->participantMapper->getParticipants($list);
		$this->checkParticipantLimit($participants);
		foreach ($participants as $participant) {
			if ($participant->getUserId() === $user->getUID()) {
				throw new InvalidInputException('The user already participates.', $this->l10n->t('%s is already a participant.', [$user->getDisplayName()]));
			}
		}

		$participant = new Participant();
		$participant->setListId($list->getId());
		$participant->setUserId($user->getUID());
		$participant->generateId();
		return $this->participantMapper->insert($participant);
	}

	/**
	 * Add a guest to a list.
	 *
	 * @throws InvalidInputException If the name is empty or the email address is invalid or already used
	 * @throws InvalidArgumentException If a value is too long
	 */
	public function addGuest(SecretSantaList $list, string $displayName, string $email): Participant {
		$participants = $this->participantMapper->getParticipants($list);
		$this->checkParticipantLimit($participants);

		$participant = new Participant();
		$participant->setListId($list->getId());
		$this->setGuestDetails($participant, $participants, $displayName, $email);
		$participant->generateId();
		return $this->participantMapper->insert($participant);
	}

	/**
	 * Change the name and email address of a guest.
	 *
	 * @throws InvalidInputException If the name is empty or the email address is invalid or already used
	 * @throws InvalidArgumentException If the participant is a Nextcloud user or a value is too long
	 */
	public function updateGuest(SecretSantaList $list, Participant $participant, string $displayName, string $email): Participant {
		if (!$participant->isGuest()) {
			throw new InvalidArgumentException('Only guests can be changed.');
		}
		$this->setGuestDetails($participant, $this->participantMapper->getParticipants($list), $displayName, $email);
		return $this->participantMapper->update($participant);
	}

	public function delete(Participant $participant): void {
		$this->participantMapper->delete($participant);
	}

	/**
	 * Remove a user from all lists.
	 */
	public function deleteByUser(string $userId): void {
		foreach ($this->participantMapper->getParticipantsByUser($userId) as $participant) {
			$this->delete($participant);
		}
	}

	/**
	 * @return SecretSantaParticipant
	 */
	public function serialize(Participant $participant): array {
		$userId = $participant->getUserId();
		if ($userId === null) {
			return [
				'id' => $participant->getId(),
				'userId' => null,
				'displayName' => $participant->getDisplayName() ?? '',
				'email' => $participant->getEmail(),
				'hasEmail' => true,
			];
		}

		$user = $this->userManager->get($userId);
		return [
			'id' => $participant->getId(),
			'userId' => $userId,
			'displayName' => $user?->getDisplayName() ?? $userId,
			'email' => null,
			'hasEmail' => ($user?->getEMailAddress() ?? '') !== '',
		];
	}

	/**
	 * Check if the owner of a list is allowed to add the user, following the sharing restrictions.
	 */
	private function canAddUser(string $ownerId, IUser $user): bool {
		if ($ownerId === $user->getUID() || !$this->shareManager->shareWithGroupMembersOnly()) {
			return true;
		}

		$owner = $this->userManager->get($ownerId);
		if ($owner === null) {
			return false;
		}
		$commonGroups = array_intersect(
			$this->groupManager->getUserGroupIds($owner),
			$this->groupManager->getUserGroupIds($user),
		);
		return array_diff($commonGroups, $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList()) !== [];
	}

	/**
	 * @param list<Participant> $participants The current participants of the list
	 * @throws InvalidInputException If the list is full
	 */
	private function checkParticipantLimit(array $participants): void {
		if (count($participants) >= self::MAX_PARTICIPANTS) {
			throw new InvalidInputException(
				'The list has too many participants.',
				$this->l10n->t('A list can not have more than %d participants.', [self::MAX_PARTICIPANTS]),
			);
		}
	}

	/**
	 * Validate and set the name and email address of a guest.
	 *
	 * @param list<Participant> $participants The participants of the list, to check that the email address is not used twice
	 * @throws InvalidInputException If the name is empty or the email address is invalid or already used
	 * @throws InvalidArgumentException If a value is too long
	 */
	private function setGuestDetails(Participant $participant, array $participants, string $displayName, string $email): void {
		$displayName = trim($displayName);
		$email = trim($email);
		if ($displayName === '') {
			throw new InvalidInputException('The name must not be empty.', $this->l10n->t('The name of the participant must not be empty.'));
		}
		if (mb_strlen($displayName) > self::MAX_LENGTH || mb_strlen($email) > self::MAX_LENGTH) {
			throw new InvalidArgumentException(sprintf('The name and email address must not be longer than %d characters.', self::MAX_LENGTH));
		}
		if (!$this->emailValidator->isValid($email)) {
			throw new InvalidInputException('Invalid email address.', $this->l10n->t('The email address is not valid.'));
		}
		foreach ($participants as $other) {
			$otherEmail = $other->getEmail();
			if ($otherEmail !== null && $other->getId() !== $participant->getId() && mb_strtolower($otherEmail) === mb_strtolower($email)) {
				throw new InvalidInputException('The email address is already used.', $this->l10n->t('Another participant already uses this email address.'));
			}
		}

		$participant->setDisplayName($displayName);
		$participant->setEmail($email);
	}
}
