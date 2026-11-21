<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Db;

final class SnowflakeId {

	/** The largest value of a BIGINT column on all supported databases */
	private const string MAX = '9223372036854775807';

	/**
	 * Check if a string is a decimal id that fits into a BIGINT column.
	 *
	 * Larger values make the query fail on PostgreSQL instead of finding no row.
	 */
	public static function isValid(string $id): bool {
		if (preg_match('/^\d{1,19}$/D', $id) !== 1) {
			return false;
		}
		return strlen($id) < strlen(self::MAX) || strcmp($id, self::MAX) <= 0;
	}

	/**
	 * Compare two valid ids numerically, as they can exceed the integer range of PHP on 32 bit systems.
	 *
	 * @return int A negative number, zero or a positive number if the first id is smaller, equal or larger
	 */
	public static function compare(string $a, string $b): int {
		return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
	}
}
