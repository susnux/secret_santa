<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Service;

use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCA\SecretSanta\Db\Share;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ShareService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShareServiceTest extends TestCase {

	private const string OWNER = 'secret-santa-share-test-owner';
	private const string GROUP = 'secret-santa-share-test-group';
	private const string OTHER_GROUP = 'secret-santa-share-test-other';

	private ListService $listService;
	private ShareService $service;
	private IConfig $config;
	private IUser $owner;
	private IGroup $group;
	private SecretSantaList $list;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->listService = Server::get(ListService::class);
		$this->service = Server::get(ShareService::class);
		$this->config = Server::get(IConfig::class);

		$userManager = Server::get(IUserManager::class);
		$userManager->get(self::OWNER)?->delete();
		$owner = $userManager->createUser(self::OWNER, bin2hex(random_bytes(16)) . 'Aa1!');
		$this->assertInstanceOf(IUser::class, $owner);
		$this->owner = $owner;
		$this->group = $this->createGroup(self::GROUP);
		$this->group->setDisplayName('Family');
		$this->group->addUser($this->owner);
		$this->list = $this->listService->create(self::OWNER, 'Christmas');
	}

	#[\Override]
	protected function tearDown(): void {
		foreach (Server::get(SecretSantaListMapper::class)->getListsByOwner(self::OWNER) as $list) {
			$this->listService->delete($list);
		}
		$this->owner->delete();
		$groupManager = Server::get(IGroupManager::class);
		$groupManager->get(self::GROUP)?->delete();
		$groupManager->get(self::OTHER_GROUP)?->delete();
		$this->config->deleteAppValue('core', 'shareapi_only_share_with_group_members');
		$this->config->deleteAppValue('core', 'shareapi_only_share_with_group_members_exclude_group_list');
		$this->config->deleteAppValue('core', 'shareapi_allow_group_sharing');
		parent::tearDown();
	}

	private function createGroup(string $groupId): IGroup {
		$groupManager = Server::get(IGroupManager::class);
		$groupManager->get($groupId)?->delete();
		$group = $groupManager->createGroup($groupId);
		$this->assertInstanceOf(IGroup::class, $group);
		return $group;
	}

	/**
	 * @return list<string>
	 */
	private function getGroupIds(SecretSantaList $list): array {
		return array_map(static fn (Share $share): string => $share->groupId, $this->service->getShares($list));
	}

	public function testShare(): void {
		$this->createGroup(self::OTHER_GROUP);

		$share = $this->service->share($this->list, self::GROUP, self::OWNER);
		$this->service->share($this->list, self::OTHER_GROUP, self::OWNER);

		$this->assertSame($this->list->getId(), $share->listId);
		$this->assertSame([self::GROUP, self::OTHER_GROUP], $this->getGroupIds($this->list));
		$this->assertSame(['groupId' => self::GROUP, 'displayName' => 'Family'], $this->service->serialize($share));
	}

	public function testShareRejectsUnknownGroup(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Unknown group.');
		$this->service->share($this->list, 'secret-santa-share-test-unknown', self::OWNER);
	}

	public function testShareRejectsDuplicate(): void {
		$this->service->share($this->list, self::GROUP, self::OWNER);

		try {
			$this->service->share($this->list, self::GROUP, self::OWNER);
			$this->fail('Sharing twice with the same group must be refused');
		} catch (InvalidInputException $error) {
			$this->assertSame('The list is already shared with the group.', $error->getMessage());
		}
		$this->assertSame([self::GROUP], $this->getGroupIds($this->list));
	}

	public static function dataShareRestrictions(): array {
		return [
			'group sharing disabled' => [['shareapi_allow_group_sharing' => 'no'], self::GROUP],
			'only own groups, not a member' => [['shareapi_only_share_with_group_members' => 'yes'], self::OTHER_GROUP],
			'only own groups, own group is excluded' => [
				[
					'shareapi_only_share_with_group_members' => 'yes',
					'shareapi_only_share_with_group_members_exclude_group_list' => json_encode([self::GROUP]),
				],
				self::GROUP,
			],
		];
	}

	/**
	 * @param array<string, string> $config App config values of core
	 */
	#[DataProvider('dataShareRestrictions')]
	public function testShareFollowsTheSharingRestrictions(array $config, string $groupId): void {
		$this->createGroup(self::OTHER_GROUP);
		foreach ($config as $key => $value) {
			$this->config->setAppValue('core', $key, $value);
		}

		try {
			$this->service->share($this->list, $groupId, self::OWNER);
			$this->fail('Sharing must be refused');
		} catch (InvalidInputException $error) {
			$this->assertSame('Sharing with the group is not allowed.', $error->getMessage());
			$this->assertNotSame('', $error->getHint());
		}
		$this->assertSame([], $this->getGroupIds($this->list));
	}

	public function testShareWithOwnGroupIfRestricted(): void {
		$this->config->setAppValue('core', 'shareapi_only_share_with_group_members', 'yes');

		$this->service->share($this->list, self::GROUP, self::OWNER);

		$this->assertSame([self::GROUP], $this->getGroupIds($this->list));
	}

	public function testUnshare(): void {
		$this->createGroup(self::OTHER_GROUP);
		$this->service->share($this->list, self::GROUP, self::OWNER);
		$this->service->share($this->list, self::OTHER_GROUP, self::OWNER);

		$this->service->unshare($this->list, self::GROUP);

		$this->assertSame([self::OTHER_GROUP], $this->getGroupIds($this->list));
	}

	public function testRemoveGroupKeepsListsWithOwner(): void {
		$this->service->share($this->list, self::GROUP, self::OWNER);

		$this->service->removeGroup(self::GROUP);

		$this->assertSame([], $this->getGroupIds($this->list));
		$this->assertSame(self::OWNER, Server::get(SecretSantaListMapper::class)->getList($this->list->getId())->getOwner());
	}

	public function testRemoveGroupDeletesListsWithoutOwnerAndShares(): void {
		$this->createGroup(self::OTHER_GROUP);
		$this->service->share($this->list, self::GROUP, self::OWNER);
		$this->service->share($this->list, self::OTHER_GROUP, self::OWNER);
		$this->listService->removeOwner(self::OWNER);
		$listMapper = Server::get(SecretSantaListMapper::class);

		$this->service->removeGroup(self::GROUP);
		$orphan = $listMapper->getList($this->list->getId());
		$this->assertNull($orphan->getOwner());
		$this->assertSame([self::OTHER_GROUP], $this->getGroupIds($orphan));

		$this->service->removeGroup(self::OTHER_GROUP);
		$this->assertSame([], $listMapper->getListsByIds([$this->list->getId()]));
	}
}
