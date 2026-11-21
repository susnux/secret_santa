<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Migration;

use Closure;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the archiving of lists
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000005Date20261121220000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$lists = $schema->getTable(SecretSantaListMapper::TABLE_NAME);
		if ($lists->hasColumn('archived_at')) {
			return null;
		}
		// Unix timestamp of archiving the list, null if the list is not archived
		$lists->addColumn('archived_at', Types::BIGINT, [
			'notnull' => false,
			'length' => 20,
			'unsigned' => true,
		]);
		return $schema;
	}
}
