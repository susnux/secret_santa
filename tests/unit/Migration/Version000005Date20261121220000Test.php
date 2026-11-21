<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SecretSanta\Migration\Version000005Date20261121220000;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000005Date20261121220000Test extends TestCase {

	public function testColumnExists(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertFalse($schema->getTable('secret_santa_lists')->getColumn('archived_at')->getNotnull());
	}

	public function testMigrationIsIdempotent(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertNull((new Version000005Date20261121220000())->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}
}
