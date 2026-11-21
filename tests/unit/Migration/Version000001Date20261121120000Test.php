<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SecretSanta\Migration\Version000001Date20261121120000;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000001Date20261121120000Test extends TestCase {

	public function testIdsAreNotAutoincrement(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertFalse($schema->getTable('secret_santa_lists')->getColumn('id')->getAutoincrement());
		$this->assertFalse($schema->getTable('secret_santa_participants')->getColumn('id')->getAutoincrement());
	}

	public function testIndexesExist(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertSame(['owner'], $schema->getTable('secret_santa_lists')->getIndex('secret_santa_lists_owner_idx')->getColumns());
		$participants = $schema->getTable('secret_santa_participants');
		$this->assertSame(['list_id'], $participants->getIndex('secret_santa_part_list_idx')->getColumns());
		$this->assertSame(['user_id'], $participants->getIndex('secret_santa_part_user_idx')->getColumns());
	}

	public function testMigrationIsIdempotent(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));
		$migration = new Version000001Date20261121120000();

		$this->assertNull($migration->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}
}
