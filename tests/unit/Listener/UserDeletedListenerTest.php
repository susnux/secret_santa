<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Listener;

use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCA\SecretSanta\Db\ShareMapper;
use OCA\SecretSanta\Listener\UserDeletedListener;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

final class UserDeletedListenerTest extends TestCase {

	private const string OWNER = 'secret-santa-listener-test-owner';
	private const string USER = 'secret-santa-listener-test-user';

	private ListService $listService;
	private ParticipantService $participantService;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->listService = Server::get(ListService::class);
		$this->participantService = Server::get(ParticipantService::class);
	}

	#[\Override]
	protected function tearDown(): void {
		$this->listService->removeOwner(self::OWNER);
		$this->listService->removeOwner(self::USER);
		Server::get(IUserManager::class)->get(self::USER)?->delete();
		parent::tearDown();
	}

	public function testKeepsSharedListsWithoutOwner(): void {
		$user = Server::get(IUserManager::class)->createUser(self::USER, bin2hex(random_bytes(16)) . 'Aa1!');
		$this->assertInstanceOf(IUser::class, $user);
		$shared = $this->listService->create(self::USER, 'Shared list');
		Server::get(ShareMapper::class)->insert($shared, 'secret-santa-listener-test-group');
		$notShared = $this->listService->create(self::USER, 'Not shared list');

		Server::get(UserDeletedListener::class)->handle(new UserDeletedEvent($user));

		$listMapper = Server::get(SecretSantaListMapper::class);
		$orphan = $listMapper->getList($shared->getId());
		$this->listService->delete($orphan);
		$this->assertNull($orphan->getOwner());
		$this->assertSame([], $listMapper->getListsByIds([$notShared->getId()]));
	}

	public function testDeletesListsAndParticipations(): void {
		$user = Server::get(IUserManager::class)->createUser(self::USER, bin2hex(random_bytes(16)) . 'Aa1!');
		$this->assertInstanceOf(IUser::class, $user);
		$ownList = $this->listService->create(self::USER, 'Own list');
		$this->participantService->addGuest($ownList, 'Alice', 'alice@example.com');
		$otherList = $this->listService->create(self::OWNER, 'Other list');
		$this->participantService->addUser($otherList, self::USER, self::OWNER);
		$guest = $this->participantService->addGuest($otherList, 'Bob', 'bob@example.com');

		Server::get(UserDeletedListener::class)->handle(new UserDeletedEvent($user));

		$this->assertSame([], $this->listService->getAccessible(self::USER));
		$this->assertSame(
			[$guest->getId()],
			array_map(static fn ($participant) => $participant->getId(), $this->participantService->getParticipants($otherList)),
		);
	}
}
