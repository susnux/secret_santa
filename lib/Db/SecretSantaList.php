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
 * A list of participants who give each other gifts
 *
 * @psalm-suppress PropertyNotSetInConstructor Properties are populated by the mapper
 * @psalm-suppress PossiblyUnusedProperty Properties are accessed through the magic getters and setters
 *
 * @method ?string getOwner()
 * @method void setOwner(?string $value)
 * @method string getName()
 * @method void setName(string $value)
 * @method ?string getSenderName()
 * @method void setSenderName(?string $value)
 * @method string getMailSubject()
 * @method void setMailSubject(string $value)
 * @method string getMailBody()
 * @method void setMailBody(string $value)
 * @method ?bool getExclusionsImported()
 * @method void setExclusionsImported(bool $value)
 * @method ?int getArchivedAt()
 * @method void setArchivedAt(?int $value)
 */
final class SecretSantaList extends SnowflakeAwareEntity {

	/**
	 * The user who organizes the list, null if the owner was deleted while the list was shared
	 * @var ?string
	 */
	protected $owner;

	/** @var string */
	protected $name;

	/**
	 * Display name of the sender of the emails, the display name of the owner if null
	 * @var ?string
	 */
	protected $senderName;

	/**
	 * Subject of the email sent to every participant
	 * @var string
	 */
	protected $mailSubject;

	/**
	 * Body of the email sent to every participant
	 * @var string
	 */
	protected $mailBody;

	/**
	 * Partners of draws were imported as exclusions
	 * @var ?bool
	 */
	protected $exclusionsImported;

	/**
	 * Unix timestamp of archiving the list, null if it is not archived. Archived lists can not be changed.
	 * @var ?int
	 */
	protected $archivedAt;

	public function __construct() {
		$this->addType('owner', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('senderName', Types::STRING);
		$this->addType('mailSubject', Types::STRING);
		$this->addType('mailBody', Types::STRING);
		$this->addType('exclusionsImported', Types::BOOLEAN);
		$this->addType('archivedAt', Types::BIGINT);
	}

}
