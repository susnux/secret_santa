<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\IShare;

/**
 * Shares of lists with groups, stored without an entity as the table has a composite primary key
 */
final class ShareMapper {

	public const string TABLE_NAME = 'secret_santa_shares';

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<Share> The shares of the list, ordered by group
	 */
	public function getShares(SecretSantaList $list): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('list_id', 'share_with')
			->from(self::TABLE_NAME)
			->where(
				$qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())),
				$qb->expr()->eq('share_type', $qb->createNamedParameter(IShare::TYPE_GROUP, IQueryBuilder::PARAM_INT)),
			)
			->orderBy('share_with');
		return $this->fetchShares($qb);
	}

	/**
	 * @param list<string> $groupIds
	 * @return list<Share> The shares with any of the groups
	 */
	public function getSharesWithGroups(array $groupIds): array {
		$shares = [];
		foreach (array_chunk($groupIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('list_id', 'share_with')
				->from(self::TABLE_NAME)
				->where(
					$qb->expr()->eq('share_type', $qb->createNamedParameter(IShare::TYPE_GROUP, IQueryBuilder::PARAM_INT)),
					$qb->expr()->in('share_with', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)),
				);
			$shares = [...$shares, ...$this->fetchShares($qb)];
		}
		return $shares;
	}

	public function insert(SecretSantaList $list, string $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'list_id' => $qb->createNamedParameter($list->getId()),
				'share_type' => $qb->createNamedParameter(IShare::TYPE_GROUP, IQueryBuilder::PARAM_INT),
				'share_with' => $qb->createNamedParameter($groupId),
			])
			->executeStatement();
	}

	public function delete(SecretSantaList $list, string $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where(
				$qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())),
				$qb->expr()->eq('share_type', $qb->createNamedParameter(IShare::TYPE_GROUP, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('share_with', $qb->createNamedParameter($groupId)),
			)
			->executeStatement();
	}

	public function deleteByList(SecretSantaList $list): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())))
			->executeStatement();
	}

	public function deleteByGroup(string $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where(
				$qb->expr()->eq('share_type', $qb->createNamedParameter(IShare::TYPE_GROUP, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('share_with', $qb->createNamedParameter($groupId)),
			)
			->executeStatement();
	}

	/**
	 * @return list<Share>
	 */
	private function fetchShares(IQueryBuilder $qb): array {
		$shares = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetchAssociative()) !== false) {
			$shares[] = new Share((string)$row['list_id'], (string)$row['share_with']);
		}
		$result->closeCursor();
		return $shares;
	}
}
