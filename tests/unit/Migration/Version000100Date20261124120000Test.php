<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SecretSanta\Migration\Version000100Date20261124120000;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000100Date20261124120000Test extends TestCase {

	private SchemaWrapper $schema;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->schema = new SchemaWrapper(Server::get(Connection::class));
	}

	public function testListsTable(): void {
		$lists = $this->schema->getTable('secret_santa_lists');

		$this->assertFalse($lists->getColumn('id')->getAutoincrement());
		$this->assertFalse($lists->getColumn('owner')->getNotnull());
		$this->assertFalse($lists->getColumn('exclusions_imported')->getNotnull());
		$this->assertFalse($lists->getColumn('archived_at')->getNotnull());
		$this->assertSame(['owner'], $lists->getIndex('secret_santa_lists_owner_idx')->getColumns());
	}

	public function testParticipantsTable(): void {
		$participants = $this->schema->getTable('secret_santa_participants');

		$this->assertFalse($participants->getColumn('id')->getAutoincrement());
		$this->assertSame(['list_id'], $participants->getIndex('secret_santa_part_list_idx')->getColumns());
		$this->assertSame(['user_id'], $participants->getIndex('secret_santa_part_user_idx')->getColumns());
	}

	public function testExclusionsTable(): void {
		$exclusions = $this->schema->getTable('secret_santa_exclusions');

		$this->assertSame(['giver_id', 'receiver_id', 'imported'], $exclusions->getPrimaryKey()?->getColumns());
		$this->assertTrue($exclusions->getColumn('imported')->getNotnull());
		$this->assertSame(['list_id'], $exclusions->getIndex('secret_santa_excl_list_idx')->getColumns());
		$this->assertSame(['receiver_id'], $exclusions->getIndex('secret_santa_excl_recv_idx')->getColumns());
	}

	public function testDrawsAndPairingsTables(): void {
		$draws = $this->schema->getTable('secret_santa_draws');
		$this->assertFalse($draws->getColumn('id')->getAutoincrement());
		$this->assertSame(['list_id'], $draws->getIndex('secret_santa_draws_list_idx')->getColumns());

		$pairings = $this->schema->getTable('secret_santa_pairings');
		$this->assertSame(['draw_id', 'giver_id'], $pairings->getPrimaryKey()?->getColumns());
		$this->assertFalse($pairings->getColumn('notified_at')->getNotnull());
		$this->assertSame(['list_id'], $pairings->getIndex('secret_santa_pair_list_idx')->getColumns());
		$this->assertSame(['giver_id'], $pairings->getIndex('secret_santa_pair_giver_idx')->getColumns());
		$this->assertSame(['receiver_id'], $pairings->getIndex('secret_santa_pair_recv_idx')->getColumns());
	}

	public function testSharesTable(): void {
		$shares = $this->schema->getTable('secret_santa_shares');

		$this->assertSame(['list_id', 'share_type', 'share_with'], $shares->getPrimaryKey()?->getColumns());
		$this->assertSame(['share_type', 'share_with'], $shares->getIndex('secret_santa_shares_with_idx')->getColumns());
	}

	public function testMigrationIsIdempotent(): void {
		$this->assertNull((new Version000100Date20261124120000())->changeSchema($this->createStub(IOutput::class), fn () => $this->schema, []));
	}
}
