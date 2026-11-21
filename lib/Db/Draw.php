<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

use OCP\AppFramework\Db\SnowflakeAwareEntity;
use OCP\DB\Types;

/**
 * A draw of the partners of a list, the time of the draw is part of its Snowflake ID
 *
 * @psalm-suppress PropertyNotSetInConstructor Properties are populated by the mapper
 * @psalm-suppress PossiblyUnusedProperty Properties are accessed through the magic getters and setters
 *
 * @method string getListId()
 * @method void setListId(string $value)
 */
final class Draw extends SnowflakeAwareEntity {

	/** @var string */
	protected $listId;

	public function __construct() {
		$this->addType('listId', Types::STRING);
	}
}
