<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Migration;

use Closure;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the table of the partners participants must not draw
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000002Date20261121150000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(ExclusionMapper::TABLE_NAME)) {
			return null;
		}

		$table = $schema->createTable(ExclusionMapper::TABLE_NAME);
		foreach (['list_id', 'giver_id', 'receiver_id'] as $column) {
			$table->addColumn($column, Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
		}
		$table->setPrimaryKey(['giver_id', 'receiver_id']);
		$table->addIndex(['list_id'], 'secret_santa_excl_list_idx');
		$table->addIndex(['receiver_id'], 'secret_santa_excl_recv_idx');
		return $schema;
	}
}
