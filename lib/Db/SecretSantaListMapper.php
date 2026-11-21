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
}
