<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

/**
 * Name and email address of a participant
 */
final readonly class Recipient {

	public function __construct(
		public string $displayName,
		public string $email,
	) {
	}
}
