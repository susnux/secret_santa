<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Migration;

use Closure;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Store the email address the email of a pairing was sent to
 *
 * Emails sent before are assumed to be sent to the current address of the participant.
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000101Date20261124150000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable(PairingMapper::TABLE_NAME);
		if ($table->hasColumn('notified_email')) {
			return null;
		}

		$table->addColumn('notified_email', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		return $schema;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('pa.giver_id', 'pa.draw_id', 'pt.user_id', 'pt.email')
			->from(PairingMapper::TABLE_NAME, 'pa')
			->innerJoin('pa', ParticipantMapper::TABLE_NAME, 'pt', $qb->expr()->eq('pa.giver_id', 'pt.id'))
			->where(
				$qb->expr()->isNotNull('pa.notified_at'),
				$qb->expr()->isNull('pa.notified_email'),
			);

		$update = $this->db->getQueryBuilder();
		$update->update(PairingMapper::TABLE_NAME)
			->set('notified_email', $update->createParameter('email'))
			->where(
				$update->expr()->eq('draw_id', $update->createParameter('draw_id')),
				$update->expr()->eq('giver_id', $update->createParameter('giver_id')),
			);

		$result = $qb->executeQuery();
		while (($row = $result->fetchAssociative()) !== false) {
			$email = $row['user_id'] === null
				? (string)$row['email']
				: ($this->userManager->get((string)$row['user_id'])?->getEMailAddress() ?? '');
			if ($email === '') {
				continue;
			}
			$update->setParameter('email', $email)
				->setParameter('draw_id', $row['draw_id'])
				->setParameter('giver_id', $row['giver_id'])
				->executeStatement();
		}
		$result->closeCursor();
	}
}
