<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Migration;

use Closure;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the tables of the lists and their participants
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000001Date20261121120000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable(SecretSantaListMapper::TABLE_NAME)) {
			$this->createListsTable($schema);
			$changed = true;
		}

		if (!$schema->hasTable(ParticipantMapper::TABLE_NAME)) {
			$this->createParticipantsTable($schema);
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	private function createListsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(SecretSantaListMapper::TABLE_NAME);
		// Snowflake ID
		$table->addColumn('id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->addColumn('owner', Types::STRING, [
			'notnull' => true,
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
		$table->setPrimaryKey(['id']);
		$table->addIndex(['owner'], 'secret_santa_lists_owner_idx');
	}

	private function createParticipantsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(ParticipantMapper::TABLE_NAME);
		// Snowflake ID
		$table->addColumn('id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->addColumn('list_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
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
}
