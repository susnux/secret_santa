<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SecretSanta\Migration\Version000002Date20261121150000;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000002Date20261121150000Test extends TestCase {

	public function testTableExists(): void {
		$table = (new SchemaWrapper(Server::get(Connection::class)))->getTable('secret_santa_exclusions');

		$this->assertSame(['giver_id', 'receiver_id'], $table->getPrimaryKey()?->getColumns());
		$this->assertSame(['list_id'], $table->getIndex('secret_santa_excl_list_idx')->getColumns());
		$this->assertSame(['receiver_id'], $table->getIndex('secret_santa_excl_recv_idx')->getColumns());
	}

	public function testMigrationIsIdempotent(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertNull((new Version000002Date20261121150000())->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}
}
