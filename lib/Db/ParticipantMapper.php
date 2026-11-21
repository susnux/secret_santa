<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Participant>
 */
final class ParticipantMapper extends QBMapper {

	public const string TABLE_NAME = 'secret_santa_participants';

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		IDBConnection $db,
	) {
		parent::__construct($db, self::TABLE_NAME, Participant::class);
	}

	/**
	 * @throws DoesNotExistException If the participant does not exist or is not part of the list
	 */
	public function getParticipant(SecretSantaList $list, string $id): Participant {
		if (!SnowflakeId::isValid($id)) {
			throw new DoesNotExistException('Invalid participant id');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id)),
				$qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())),
			);
		return $this->findEntity($qb);
	}

	/**
	 * @return list<Participant>
	 */
	public function getParticipants(SecretSantaList $list): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Get the participations of a user in all lists
	 *
	 * @return list<Participant>
	 */
	public function getParticipantsByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntities($qb);
	}

	public function deleteByList(SecretSantaList $list): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())))
			->executeStatement();
	}
}
