<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SecretSanta\Migration\Version000004Date20261121200000;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000004Date20261121200000Test extends TestCase {

	public function testColumnsExist(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$exclusions = $schema->getTable('secret_santa_exclusions');
		$this->assertSame(['giver_id', 'receiver_id', 'imported'], $exclusions->getPrimaryKey()?->getColumns());
		$this->assertTrue($exclusions->getColumn('imported')->getNotnull());
		$this->assertFalse($schema->getTable('secret_santa_lists')->getColumn('exclusions_imported')->getNotnull());
	}

	public function testMigrationIsIdempotent(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertNull((new Version000004Date20261121200000())->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}
}
