<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SecretSanta\Migration\Version000003Date20261121180000;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000003Date20261121180000Test extends TestCase {

	public function testTablesExist(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$draws = $schema->getTable('secret_santa_draws');
		$this->assertFalse($draws->getColumn('id')->getAutoincrement());
		$this->assertSame(['list_id'], $draws->getIndex('secret_santa_draws_list_idx')->getColumns());

		$pairings = $schema->getTable('secret_santa_pairings');
		$this->assertSame(['draw_id', 'giver_id'], $pairings->getPrimaryKey()?->getColumns());
		$this->assertFalse($pairings->getColumn('notified_at')->getNotnull());
		$this->assertSame(['list_id'], $pairings->getIndex('secret_santa_pair_list_idx')->getColumns());
		$this->assertSame(['giver_id'], $pairings->getIndex('secret_santa_pair_giver_idx')->getColumns());
		$this->assertSame(['receiver_id'], $pairings->getIndex('secret_santa_pair_recv_idx')->getColumns());
	}

	public function testMigrationIsIdempotent(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertNull((new Version000003Date20261121180000())->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}
}
