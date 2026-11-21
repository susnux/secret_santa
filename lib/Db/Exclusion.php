<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

/**
 * The giver must not draw the receiver as their partner
 */
final readonly class Exclusion {

	public function __construct(
		public string $giverId,
		public string $receiverId,
	) {
	}
}
