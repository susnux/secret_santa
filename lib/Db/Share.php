<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

/**
 * A list is shared with a group, all members of the group can change it
 */
final readonly class Share {

	public function __construct(
		public string $listId,
		public string $groupId,
	) {
	}
}
