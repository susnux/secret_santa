<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Listener;

use OCA\SecretSanta\Db\ShareMapper;
use OCA\SecretSanta\Listener\GroupDeletedListener;
use OCA\SecretSanta\Service\ListService;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroup;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class GroupDeletedListenerTest extends TestCase {

	private const string OWNER = 'secret-santa-group-listener-test-owner';
	private const string GROUP = 'secret-santa-group-listener-test-group';

	public function testRemovesTheSharesWithTheGroup(): void {
		$listService = Server::get(ListService::class);
		$shareMapper = Server::get(ShareMapper::class);
		$list = $listService->create(self::OWNER, 'Christmas');
		$shareMapper->insert($list, self::GROUP);
		$shareMapper->insert($list, self::GROUP . '-other');
		$group = $this->createStub(IGroup::class);
		$group->method('getGID')->willReturn(self::GROUP);

		try {
			Server::get(GroupDeletedListener::class)->handle(new GroupDeletedEvent($group));

			$this->assertSame([self::GROUP . '-other'], array_map(static fn ($share) => $share->groupId, $shareMapper->getShares($list)));
		} finally {
			$listService->delete($list);
		}
	}
}
