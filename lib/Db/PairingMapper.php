<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class PairingMapper {

	public const string TABLE_NAME = 'secret_santa_pairings';

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<Pairing>
	 */
	public function getPairings(Draw $draw): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('giver_id', 'receiver_id', 'notified_at', 'notified_email')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('draw_id', $qb->createNamedParameter($draw->getId())))
			->orderBy('giver_id');

		$pairings = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetchAssociative()) !== false) {
			$pairings[] = new Pairing(
				(string)$row['giver_id'],
				(string)$row['receiver_id'],
				$row['notified_at'] === null ? null : (int)$row['notified_at'],
				$row['notified_email'] === null ? null : (string)$row['notified_email'],
			);
		}
		$result->closeCursor();
		return $pairings;
	}

	public function insert(SecretSantaList $list, Draw $draw, Pairing $pairing): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'list_id' => $qb->createNamedParameter($list->getId()),
				'draw_id' => $qb->createNamedParameter($draw->getId()),
				'giver_id' => $qb->createNamedParameter($pairing->giverId),
				'receiver_id' => $qb->createNamedParameter($pairing->receiverId),
				'notified_at' => $qb->createNamedParameter($pairing->notifiedAt, $pairing->notifiedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'notified_email' => $qb->createNamedParameter($pairing->notifiedEmail, $pairing->notifiedEmail === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			])
			->executeStatement();
	}

	/**
	 * Store that the email was sent to the giver
	 *
	 * @param int $time Unix timestamp of the email
	 * @param string $email Email address the email was sent to
	 */
	public function setNotified(Draw $draw, string $giverId, int $time, string $email): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('notified_at', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT))
			->set('notified_email', $qb->createNamedParameter($email))
			->where(
				$qb->expr()->eq('draw_id', $qb->createNamedParameter($draw->getId())),
				$qb->expr()->eq('giver_id', $qb->createNamedParameter($giverId)),
			)
			->executeStatement();
	}

	/**
	 * Delete the pairings of a participant as giver.
	 *
	 * The pairings with the participant as receiver are kept, deleting them would reveal who drew the participant.
	 */
	public function deleteByGiver(Participant $participant): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('giver_id', $qb->createNamedParameter($participant->getId())))
			->executeStatement();
	}

	public function deleteByList(SecretSantaList $list): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())))
			->executeStatement();
	}
}
