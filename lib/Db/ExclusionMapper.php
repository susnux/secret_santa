<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class ExclusionMapper {

	public const string TABLE_NAME = 'secret_santa_exclusions';

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param ?bool $imported Only the exclusions imported from draws (true) or set by hand (false), all if null
	 * @return list<Exclusion>
	 */
	public function getExclusions(SecretSantaList $list, ?bool $imported = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('giver_id', 'receiver_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())))
			->orderBy('giver_id')
			->addOrderBy('receiver_id');
		if ($imported !== null) {
			$qb->andWhere($qb->expr()->eq('imported', $qb->createNamedParameter((int)$imported, IQueryBuilder::PARAM_INT)));
		}

		$exclusions = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetchAssociative()) !== false) {
			$exclusions[] = new Exclusion((string)$row['giver_id'], (string)$row['receiver_id']);
		}
		$result->closeCursor();
		return $exclusions;
	}

	public function insert(SecretSantaList $list, string $giverId, string $receiverId, bool $imported): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'list_id' => $qb->createNamedParameter($list->getId()),
				'giver_id' => $qb->createNamedParameter($giverId),
				'receiver_id' => $qb->createNamedParameter($receiverId),
				'imported' => $qb->createNamedParameter((int)$imported, IQueryBuilder::PARAM_INT),
			])
			->executeStatement();
	}

	public function delete(string $giverId, string $receiverId, bool $imported): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where(
				$qb->expr()->eq('giver_id', $qb->createNamedParameter($giverId)),
				$qb->expr()->eq('receiver_id', $qb->createNamedParameter($receiverId)),
				$qb->expr()->eq('imported', $qb->createNamedParameter((int)$imported, IQueryBuilder::PARAM_INT)),
			)
			->executeStatement();
	}

	/**
	 * Delete the exclusions of a list imported from draws
	 */
	public function deleteImported(SecretSantaList $list): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where(
				$qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())),
				$qb->expr()->eq('imported', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
			)
			->executeStatement();
	}

	/**
	 * Delete the exclusions of a participant in both directions
	 */
	public function deleteByParticipant(Participant $participant): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->orX(
				$qb->expr()->eq('giver_id', $qb->createNamedParameter($participant->getId())),
				$qb->expr()->eq('receiver_id', $qb->createNamedParameter($participant->getId())),
			))
			->executeStatement();
	}

	public function deleteByList(SecretSantaList $list): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())))
			->executeStatement();
	}
}
