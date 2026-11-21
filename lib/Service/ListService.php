<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

use InvalidArgumentException;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IL10N;

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
	) {
	}

	/**
	 * Get a list of the given user.
	 *
	 * @throws DoesNotExistException If the list does not exist or is owned by another user
	 */
	public function get(string $id, string $userId): SecretSantaList {
		$list = $this->listMapper->getList($id);
		if ($list->getOwner() !== $userId) {
			throw new DoesNotExistException('List not found');
		}
		return $list;
	}

	/**
	 * @return list<SecretSantaList>
	 */
	public function getByOwner(string $owner): array {
		return $this->listMapper->getListsByOwner($owner);
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
	 * @throws InvalidInputException If the name, subject or body is empty
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
	 * Delete a list including its participants.
	 */
	public function delete(SecretSantaList $list): void {
		$this->atomic(function () use ($list): void {
			$this->participantMapper->deleteByList($list);
			$this->listMapper->delete($list);
		}, $this->db);
	}

	/**
	 * Delete all lists owned by the given user.
	 */
	public function deleteByOwner(string $owner): void {
		foreach ($this->listMapper->getListsByOwner($owner) as $list) {
			$this->delete($list);
		}
	}

	/**
	 * @return SecretSantaListData
	 */
	public function serialize(SecretSantaList $list): array {
		return [
			'id' => $list->getId(),
			'name' => $list->getName(),
			'senderName' => $list->getSenderName(),
			'mailSubject' => $list->getMailSubject(),
			'mailBody' => $list->getMailBody(),
		];
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
