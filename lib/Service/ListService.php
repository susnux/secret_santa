<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

use InvalidArgumentException;
use OCA\SecretSanta\Db\DrawMapper;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCA\SecretSanta\Db\ShareMapper;
use OCA\SecretSanta\Db\SnowflakeId;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * @psalm-import-type SecretSantaList from ResponseDefinitions as SecretSantaListData
 */
final class ListService {
	use TTransactional;

	/** Maximum length of the name, sender name and subject (length of the database columns) */
	public const int MAX_LENGTH = 255;
	/** Maximum length of the email body */
	public const int MAX_BODY_LENGTH = 10_000;

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IDBConnection $db,
		private IL10N $l10n,
		private SecretSantaListMapper $listMapper,
		private ParticipantMapper $participantMapper,
		private ExclusionMapper $exclusionMapper,
		private DrawMapper $drawMapper,
		private PairingMapper $pairingMapper,
		private ShareMapper $shareMapper,
		private ITimeFactory $timeFactory,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * Get a list the given user owns or that is shared with one of their groups.
	 *
	 * @throws DoesNotExistException If the list does not exist or the user can not access it
	 */
	public function get(string $id, string $userId): SecretSantaList {
		$list = $this->listMapper->getList($id);
		if ($list->getOwner() !== $userId && !$this->isSharedWithUser($list, $userId)) {
			throw new DoesNotExistException('List not found');
		}
		return $list;
	}

	/**
	 * Check if the user owns the list, only the owner can delete and share it.
	 */
	public function isOwner(SecretSantaList $list, string $userId): bool {
		return $list->getOwner() === $userId;
	}

	/**
	 * The message shown to users who try to delete or share a list they do not own
	 */
	public function getOwnerOnlyHint(): string {
		return $this->l10n->t('Only the owner of the list can delete and share it.');
	}

	/**
	 * Check that a list can be changed.
	 *
	 * @throws InvalidInputException If the list is archived
	 */
	public function checkEditable(SecretSantaList $list): void {
		if ($list->getArchivedAt() !== null) {
			throw new InvalidInputException('The list is archived.', $this->l10n->t('The list is archived. Unarchive it to change it.'));
		}
	}

	/**
	 * Get the lists the user owns and the lists shared with their groups.
	 *
	 * @return list<SecretSantaList> The lists ordered by id
	 */
	public function getAccessible(string $userId): array {
		$lists = [];
		foreach ($this->listMapper->getListsByOwner($userId) as $list) {
			$lists[$list->getId()] = $list;
		}
		$sharedIds = [];
		foreach ($this->shareMapper->getSharesWithGroups($this->getGroupIds($userId)) as $share) {
			if (!isset($lists[$share->listId])) {
				$sharedIds[$share->listId] = true;
			}
		}
		foreach ($this->listMapper->getListsByIds(array_map(strval(...), array_keys($sharedIds))) as $list) {
			$lists[$list->getId()] = $list;
		}

		$lists = array_values($lists);
		usort($lists, static fn (SecretSantaList $a, SecretSantaList $b): int => SnowflakeId::compare($a->getId(), $b->getId()));
		return $lists;
	}

	/**
	 * Create a new list with the default email.
	 *
	 * @throws InvalidInputException If the name is empty
	 * @throws InvalidArgumentException If the name is too long
	 */
	public function create(string $owner, string $name): SecretSantaList {
		$list = new SecretSantaList();
		$list->setOwner($owner);
		$list->setName($this->validateName($name));
		$list->setSenderName(null);
		// TRANSLATORS Default subject of the email sent to the participants
		$list->setMailSubject($this->l10n->t('Your Secret Santa partner'));
		// TRANSLATORS Default email sent to the participants, {name} and {partner} are placeholders and must not be translated
		$list->setMailBody($this->l10n->t("Hello {name},\n\nthis year you are the Secret Santa of {partner}!\nThink of a nice gift for {partner} and keep it a secret until the gifts are given.\n\nHappy holidays!"));
		$list->generateId();
		return $this->listMapper->insert($list);
	}

	/**
	 * Change the details of a list, null values are left unchanged.
	 *
	 * @param ?string $senderName The display name of the sender, an empty string to use the display name of the owner
	 * @throws InvalidInputException If the name, subject or body is empty or the subject contains the partner
	 * @throws InvalidArgumentException If a value is too long
	 */
	public function update(
		SecretSantaList $list,
		?string $name = null,
		?string $senderName = null,
		?string $mailSubject = null,
		?string $mailBody = null,
	): SecretSantaList {
		if ($name !== null) {
			$list->setName($this->validateName($name));
		}
		if ($senderName !== null) {
			$senderName = trim($senderName);
			$this->checkLength($senderName, self::MAX_LENGTH, 'sender name');
			$list->setSenderName($senderName === '' ? null : $senderName);
		}
		if ($mailSubject !== null) {
			$mailSubject = trim($mailSubject);
			if ($mailSubject === '') {
				throw new InvalidInputException('The subject must not be empty.', $this->l10n->t('The subject of the email must not be empty.'));
			}
			if (str_contains($mailSubject, NotificationService::PLACEHOLDER_PARTNER)) {
				throw new InvalidInputException(
					'The subject must not contain the partner.',
					$this->l10n->t('The partner can only be mentioned in the text of the email, as mail servers often log the subjects of emails.'),
				);
			}
			$this->checkLength($mailSubject, self::MAX_LENGTH, 'subject');
			$list->setMailSubject($mailSubject);
		}
		if ($mailBody !== null) {
			if (trim($mailBody) === '') {
				throw new InvalidInputException('The email body must not be empty.', $this->l10n->t('The text of the email must not be empty.'));
			}
			$this->checkLength($mailBody, self::MAX_BODY_LENGTH, 'email body');
			$list->setMailBody($mailBody);
		}
		return $this->listMapper->update($list);
	}

	/**
	 * Archive a list, archived lists can not be changed until they are unarchived.
	 */
	public function archive(SecretSantaList $list): SecretSantaList {
		if ($list->getArchivedAt() === null) {
			$list->setArchivedAt($this->timeFactory->getTime());
		}
		return $this->listMapper->update($list);
	}

	public function unarchive(SecretSantaList $list): SecretSantaList {
		$list->setArchivedAt(null);
		return $this->listMapper->update($list);
	}

	/**
	 * Delete a list including its participants, exclusions and draws.
	 */
	public function delete(SecretSantaList $list): void {
		$this->atomic(function () use ($list): void {
			$this->pairingMapper->deleteByList($list);
			$this->drawMapper->deleteByList($list);
			$this->exclusionMapper->deleteByList($list);
			$this->participantMapper->deleteByList($list);
			$this->shareMapper->deleteByList($list);
			$this->listMapper->delete($list);
		}, $this->db);
	}

	/**
	 * Remove a deleted owner from their lists.
	 *
	 * Lists shared with groups are kept without owner, so the members of the groups can still use them.
	 * All other lists of the owner are deleted.
	 */
	public function removeOwner(string $owner): void {
		foreach ($this->listMapper->getListsByOwner($owner) as $list) {
			if ($this->shareMapper->getShares($list) === []) {
				$this->delete($list);
			} else {
				$list->setOwner(null);
				$this->listMapper->update($list);
			}
		}
	}

	/**
	 * @return SecretSantaListData
	 */
	public function serialize(SecretSantaList $list): array {
		$owner = $list->getOwner();
		return [
			'id' => $list->getId(),
			'owner' => $owner,
			'ownerDisplayName' => $owner === null ? null : ($this->userManager->getDisplayName($owner) ?? $owner),
			'name' => $list->getName(),
			'senderName' => $list->getSenderName(),
			'mailSubject' => $list->getMailSubject(),
			'mailBody' => $list->getMailBody(),
			'exclusionsImported' => $list->getExclusionsImported() === true,
			'archivedAt' => $list->getArchivedAt(),
		];
	}

	private function isSharedWithUser(SecretSantaList $list, string $userId): bool {
		$groupIds = array_map(static fn ($share): string => $share->groupId, $this->shareMapper->getShares($list));
		if ($groupIds === []) {
			return false;
		}
		return array_intersect($groupIds, $this->getGroupIds($userId)) !== [];
	}

	/**
	 * @return list<string>
	 */
	private function getGroupIds(string $userId): array {
		$user = $this->userManager->get($userId);
		return $user === null ? [] : $this->groupManager->getUserGroupIds($user);
	}

	/**
	 * @throws InvalidInputException If the name is empty
	 * @throws InvalidArgumentException If the name is too long
	 */
	private function validateName(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidInputException('The name of the list must not be empty.', $this->l10n->t('The name of the list must not be empty.'));
		}
		$this->checkLength($name, self::MAX_LENGTH, 'name');
		return $name;
	}

	/**
	 * @throws InvalidArgumentException If the value is too long
	 */
	private function checkLength(string $value, int $maxLength, string $field): void {
		if (mb_strlen($value) > $maxLength) {
			throw new InvalidArgumentException(sprintf('The %s must not be longer than %d characters.', $field, $maxLength));
		}
	}
}
