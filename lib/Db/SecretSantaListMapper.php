<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SecretSantaList>
 */
final class SecretSantaListMapper extends QBMapper {

	public const string TABLE_NAME = 'secret_santa_lists';

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		IDBConnection $db,
	) {
		parent::__construct($db, self::TABLE_NAME, SecretSantaList::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getList(string $id): SecretSantaList {
		if (!SnowflakeId::isValid($id)) {
			throw new DoesNotExistException('Invalid list id');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<SecretSantaList>
	 */
	public function getListsByOwner(string $owner): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Set the owner of a list if it has no owner.
	 *
	 * @return bool False if the list does not exist or already has an owner
	 */
	public function setOwnerIfNone(string $id, string $owner): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('owner', $qb->createNamedParameter($owner))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->isNull('owner'));
		return $qb->executeStatement() === 1;
	}

	/**
	 * @param list<string> $ids
	 * @return list<SecretSantaList> The existing lists of the ids, in no particular order
	 */
	public function getListsByIds(array $ids): array {
		$lists = [];
		foreach (array_chunk($ids, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->tableName)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$lists = [...$lists, ...$this->findEntities($qb)];
		}
		return $lists;
	}
}
