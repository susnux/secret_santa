<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Migration;

use Closure;
use OCA\SecretSanta\Db\DrawMapper;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCA\SecretSanta\Db\ShareMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the tables of the lists, their participants, exclusions, draws and shares
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000100Date20261124120000 extends SimpleMigrationStep {

	private const array ID_COLUMN = [
		'notnull' => true,
		'length' => 20,
		'unsigned' => true,
	];

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		$tables = [
			SecretSantaListMapper::TABLE_NAME => $this->createListsTable(...),
			ParticipantMapper::TABLE_NAME => $this->createParticipantsTable(...),
			ExclusionMapper::TABLE_NAME => $this->createExclusionsTable(...),
			DrawMapper::TABLE_NAME => $this->createDrawsTable(...),
			PairingMapper::TABLE_NAME => $this->createPairingsTable(...),
			ShareMapper::TABLE_NAME => $this->createSharesTable(...),
		];
		foreach ($tables as $name => $create) {
			if (!$schema->hasTable($name)) {
				$create($schema);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}

	private function createListsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(SecretSantaListMapper::TABLE_NAME);
		// Snowflake ID
		$table->addColumn('id', Types::BIGINT, self::ID_COLUMN);
		// Null for shared lists of deleted owners
		$table->addColumn('owner', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		$table->addColumn('name', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('sender_name', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('mail_subject', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('mail_body', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('exclusions_imported', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);
		// Unix timestamp of archiving the list, null if the list is not archived
		$table->addColumn('archived_at', Types::BIGINT, [
			'notnull' => false,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['owner'], 'secret_santa_lists_owner_idx');
	}

	private function createParticipantsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(ParticipantMapper::TABLE_NAME);
		// Snowflake ID
		$table->addColumn('id', Types::BIGINT, self::ID_COLUMN);
		$table->addColumn('list_id', Types::BIGINT, self::ID_COLUMN);
		// Set for Nextcloud users, null for guests
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		// Only stored for guests, the name and email of users are loaded from their account
		$table->addColumn('display_name', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('email', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['list_id'], 'secret_santa_part_list_idx');
		$table->addIndex(['user_id'], 'secret_santa_part_user_idx');
	}

	private function createExclusionsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(ExclusionMapper::TABLE_NAME);
		foreach (['list_id', 'giver_id', 'receiver_id'] as $column) {
			$table->addColumn($column, Types::BIGINT, self::ID_COLUMN);
		}
		// 1 if imported from a draw, these exclusions are hidden as they reveal the partners of that draw
		$table->addColumn('imported', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
			'unsigned' => true,
		]);
		// An exclusion can be set by hand and imported at the same time
		$table->setPrimaryKey(['giver_id', 'receiver_id', 'imported']);
		$table->addIndex(['list_id'], 'secret_santa_excl_list_idx');
		$table->addIndex(['receiver_id'], 'secret_santa_excl_recv_idx');
	}

	private function createDrawsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(DrawMapper::TABLE_NAME);
		// Snowflake ID, also the time of the draw
		$table->addColumn('id', Types::BIGINT, self::ID_COLUMN);
		$table->addColumn('list_id', Types::BIGINT, self::ID_COLUMN);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['list_id'], 'secret_santa_draws_list_idx');
	}

	private function createPairingsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(PairingMapper::TABLE_NAME);
		foreach (['list_id', 'draw_id', 'giver_id', 'receiver_id'] as $column) {
			$table->addColumn($column, Types::BIGINT, self::ID_COLUMN);
		}
		// Unix timestamp of the email sent to the giver, null if it could not be sent
		$table->addColumn('notified_at', Types::BIGINT, [
			'notnull' => false,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->setPrimaryKey(['draw_id', 'giver_id']);
		$table->addIndex(['list_id'], 'secret_santa_pair_list_idx');
		$table->addIndex(['giver_id'], 'secret_santa_pair_giver_idx');
		$table->addIndex(['receiver_id'], 'secret_santa_pair_recv_idx');
	}

	private function createSharesTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(ShareMapper::TABLE_NAME);
		$table->addColumn('list_id', Types::BIGINT, self::ID_COLUMN);
		// One of the IShare::TYPE_* constants, currently only groups
		$table->addColumn('share_type', Types::SMALLINT, [
			'notnull' => true,
		]);
		// The id of the group
		$table->addColumn('share_with', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->setPrimaryKey(['list_id', 'share_type', 'share_with']);
		$table->addIndex(['share_type', 'share_with'], 'secret_santa_shares_with_idx');
	}
}
