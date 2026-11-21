<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Migration;

use Closure;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add exclusions imported from the draws of other lists
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000004Date20261121200000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		$exclusions = $schema->getTable(ExclusionMapper::TABLE_NAME);
		if (!$exclusions->hasColumn('imported')) {
			// 1 if imported from a draw, these exclusions are hidden as they reveal the partners of that draw
			$exclusions->addColumn('imported', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'unsigned' => true,
			]);
			// An exclusion can be set by hand and imported at the same time
			$exclusions->dropPrimaryKey();
			$exclusions->setPrimaryKey(['giver_id', 'receiver_id', 'imported']);
			$changed = true;
		}

		$lists = $schema->getTable(SecretSantaListMapper::TABLE_NAME);
		if (!$lists->hasColumn('exclusions_imported')) {
			$lists->addColumn('exclusions_imported', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
