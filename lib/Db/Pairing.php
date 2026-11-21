<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

/**
 * The giver gives a gift to the receiver
 */
final readonly class Pairing {

	/**
	 * @param ?int $notifiedAt Unix timestamp of the email sent to the giver, null if it was not sent
	 */
	public function __construct(
		public string $giverId,
		public string $receiverId,
		public ?int $notifiedAt = null,
	) {
	}
}
