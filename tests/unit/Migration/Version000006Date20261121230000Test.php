<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SecretSanta\Migration\Version000006Date20261121230000;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000006Date20261121230000Test extends TestCase {

	public function testSchema(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertFalse($schema->getTable('secret_santa_lists')->getColumn('owner')->getNotnull());
		$shares = $schema->getTable('secret_santa_shares');
		$this->assertSame(['list_id', 'share_type', 'share_with'], $shares->getPrimaryKey()?->getColumns());
		$this->assertTrue($shares->hasIndex('secret_santa_shares_with_idx'));
	}

	public function testMigrationIsIdempotent(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertNull((new Version000006Date20261121230000())->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}
}
