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
 * @template-extends QBMapper<Draw>
 */
final class DrawMapper extends QBMapper {

	public const string TABLE_NAME = 'secret_santa_draws';

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		IDBConnection $db,
	) {
		parent::__construct($db, self::TABLE_NAME, Draw::class);
	}

	/**
	 * @throws DoesNotExistException If the draw does not exist or is not a draw of the list
	 */
	public function getDraw(SecretSantaList $list, string $id): Draw {
		if (!SnowflakeId::isValid($id)) {
			throw new DoesNotExistException('Invalid draw id');
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
	 * @return list<Draw> The draws of the list, the latest first
	 */
	public function getDraws(SecretSantaList $list): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function getLatestDraw(SecretSantaList $list): ?Draw {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function deleteByList(SecretSantaList $list): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId())))
			->executeStatement();
	}
}
