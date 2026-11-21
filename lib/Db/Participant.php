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
 * A participant of a list, either a Nextcloud user or a guest
 *
 * @psalm-suppress PropertyNotSetInConstructor Properties are populated by the mapper
 * @psalm-suppress PossiblyUnusedProperty Properties are accessed through the magic getters and setters
 *
 * @method string getListId()
 * @method void setListId(string $value)
 * @method ?string getUserId()
 * @method void setUserId(?string $value)
 * @method ?string getDisplayName()
 * @method void setDisplayName(?string $value)
 * @method ?string getEmail()
 * @method void setEmail(?string $value)
 */
final class Participant extends SnowflakeAwareEntity {

	/** @var string */
	protected $listId;

	/**
	 * The Nextcloud user, null for guests
	 * @var ?string
	 */
	protected $userId;

	/**
	 * The name of a guest, null for users
	 * @var ?string
	 */
	protected $displayName;

	/**
	 * The email address of a guest, null for users
	 * @var ?string
	 */
	protected $email;

	public function __construct() {
		$this->addType('listId', Types::STRING);
		$this->addType('userId', Types::STRING);
		$this->addType('displayName', Types::STRING);
		$this->addType('email', Types::STRING);
	}

	public function isGuest(): bool {
		return $this->userId === null;
	}
}
