<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Db;

use OCA\SecretSanta\Db\SnowflakeId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnowflakeIdTest extends TestCase {

	public static function dataIsValid(): array {
		return [
			'small id' => ['1', true],
			'snowflake id' => ['7012345678901234567', true],
			'largest bigint' => ['9223372036854775807', true],
			'larger than bigint' => ['9223372036854775808', false],
			'too many digits' => ['10000000000000000000', false],
			'empty' => ['', false],
			'negative' => ['-1', false],
			'not a number' => ['1a', false],
			'trailing newline' => ["1\n", false],
		];
	}

	#[DataProvider('dataIsValid')]
	public function testIsValid(string $id, bool $expected): void {
		$this->assertSame($expected, SnowflakeId::isValid($id));
	}

	public static function dataCompare(): array {
		return [
			'equal' => ['42', '42', 0],
			'smaller with fewer digits' => ['9', '10', -1],
			'larger with more digits' => ['7012345678901234567', '701234567890123456', 1],
			'smaller with the same digits' => ['7012345678901234566', '7012345678901234567', -1],
			'larger with the same digits' => ['9223372036854775807', '9223372036854775806', 1],
		];
	}

	#[DataProvider('dataCompare')]
	public function testCompare(string $a, string $b, int $expected): void {
		$this->assertSame($expected, SnowflakeId::compare($a, $b) <=> 0);
	}
}
