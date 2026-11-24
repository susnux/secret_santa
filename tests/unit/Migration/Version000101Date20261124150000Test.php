<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SecretSanta\Db\Draw;
use OCA\SecretSanta\Db\DrawMapper;
use OCA\SecretSanta\Db\Pairing;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Migration\Version000101Date20261124150000;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000101Date20261124150000Test extends TestCase {

	private const string OWNER = 'secret-santa-migration-test';
	private const string USER = 'secret-santa-migration-test-user';

	#[\Override]
	protected function tearDown(): void {
		Server::get(ListService::class)->removeOwner(self::OWNER);
		Server::get(IUserManager::class)->get(self::USER)?->delete();
		parent::tearDown();
	}

	public function testNotifiedEmailColumn(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));
		$column = $schema->getTable('secret_santa_pairings')->getColumn('notified_email');

		$this->assertFalse($column->getNotnull());
		$this->assertSame(255, $column->getLength());
		$this->assertNull(Server::get(Version000101Date20261124150000::class)->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}

	public function testSentEmailsGetTheCurrentAddress(): void {
		$userManager = Server::get(IUserManager::class);
		$userManager->get(self::USER)?->delete();
		$user = $userManager->createUser(self::USER, bin2hex(random_bytes(16)) . 'Aa1!');
		$this->assertInstanceOf(IUser::class, $user);
		$user->setSystemEMailAddress('bob@example.com');
		$participantService = Server::get(ParticipantService::class);
		$list = Server::get(ListService::class)->create(self::OWNER, 'Christmas');
		$alice = $participantService->addGuest($list, 'Alice', 'alice@example.com');
		$bob = $participantService->addUser($list, self::USER, self::OWNER);
		$carol = $participantService->addGuest($list, 'Carol', 'carol@example.com');
		$dave = $participantService->addGuest($list, 'Dave', 'dave@example.com');
		$draw = new Draw();
		$draw->setListId($list->getId());
		$draw->generateId();
		$draw = Server::get(DrawMapper::class)->insert($draw);
		$pairingMapper = Server::get(PairingMapper::class);
		$pairingMapper->insert($list, $draw, new Pairing($alice->getId(), $bob->getId(), 1));
		$pairingMapper->insert($list, $draw, new Pairing($bob->getId(), $carol->getId(), 1));
		$pairingMapper->insert($list, $draw, new Pairing($carol->getId(), $dave->getId()));
		$pairingMapper->insert($list, $draw, new Pairing($dave->getId(), $alice->getId(), 1, 'old@example.com'));

		$schema = new SchemaWrapper(Server::get(Connection::class));
		Server::get(Version000101Date20261124150000::class)->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);

		$this->assertSame(
			[
				$alice->getId() => 'alice@example.com',
				$bob->getId() => 'bob@example.com',
				$carol->getId() => null,
				$dave->getId() => 'old@example.com',
			],
			array_column(array_map(static fn (Pairing $pairing): array => (array)$pairing, $pairingMapper->getPairings($draw)), 'notifiedEmail', 'giverId'),
		);
	}
}
