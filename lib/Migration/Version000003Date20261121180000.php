<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Migration;

use Closure;
use OCA\SecretSanta\Db\DrawMapper;
use OCA\SecretSanta\Db\PairingMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the tables of the draws and their pairings
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000003Date20261121180000 extends SimpleMigrationStep {

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

		if (!$schema->hasTable(DrawMapper::TABLE_NAME)) {
			$table = $schema->createTable(DrawMapper::TABLE_NAME);
			// Snowflake ID, also the time of the draw
			$table->addColumn('id', Types::BIGINT, self::ID_COLUMN);
			$table->addColumn('list_id', Types::BIGINT, self::ID_COLUMN);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['list_id'], 'secret_santa_draws_list_idx');
			$changed = true;
		}

		if (!$schema->hasTable(PairingMapper::TABLE_NAME)) {
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
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
