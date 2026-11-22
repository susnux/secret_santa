<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Migration;

use Closure;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCA\SecretSanta\Db\ShareMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the sharing of lists with groups, lists of deleted owners that are shared have no owner
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000006Date20261121230000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		$owner = $schema->getTable(SecretSantaListMapper::TABLE_NAME)->getColumn('owner');
		if ($owner->getNotnull()) {
			$owner->setNotnull(false);
			$changed = true;
		}

		if (!$schema->hasTable(ShareMapper::TABLE_NAME)) {
			$table = $schema->createTable(ShareMapper::TABLE_NAME);
			$table->addColumn('list_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
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
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
