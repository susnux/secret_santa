<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Integration\Controller;

use GuzzleHttp\RequestOptions;
use OCA\SecretSanta\Tests\Integration\IntegrationTestCase;

final class ShareControllerTest extends IntegrationTestCase {

	private string $owner;
	private string $member;
	private string $outsider;
	private string $group;
	private string $listId;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->owner = $this->createUser();
		$this->member = $this->createUser();
		$this->outsider = $this->createUser();
		$this->group = $this->createGroup([$this->owner, $this->member]);
		$list = $this->assertOcsResponse(201, $this->ocsRequest($this->owner, 'POST', 'api/v1/lists', [
			RequestOptions::JSON => ['name' => 'Christmas'],
		]));
		$this->assertIsArray($list);
		$this->listId = $list['id'];
	}

	private function share(string $groupId, string $userId = ''): mixed {
		return $this->ocsRequest($userId === '' ? $this->owner : $userId, 'POST', 'api/v1/lists/' . $this->listId . '/shares', [
			RequestOptions::JSON => ['groupId' => $groupId],
		]);
	}

	/**
	 * @return list<string> The ids of the lists the user can access
	 */
	private function getListIds(string $userId): array {
		$lists = $this->assertOcsResponse(200, $this->ocsRequest($userId, 'GET', 'api/v1/lists'));
		$this->assertIsArray($lists);
		return array_column($lists, 'id');
	}

	public function testShareWithGroup(): void {
		$share = $this->assertOcsResponse(201, $this->share($this->group));

		$this->assertSame(['groupId' => $this->group, 'displayName' => $this->group], $share);
		$this->assertSame([$share], $this->assertOcsResponse(200, $this->ocsRequest($this->owner, 'GET', 'api/v1/lists/' . $this->listId . '/shares')));
		$this->assertSame([$share], $this->assertOcsResponse(200, $this->ocsRequest($this->member, 'GET', 'api/v1/lists/' . $this->listId . '/shares')));

		$list = $this->assertOcsResponse(200, $this->ocsRequest($this->member, 'GET', 'api/v1/lists/' . $this->listId));
		$this->assertIsArray($list);
		$this->assertSame($this->owner, $list['owner']);
		$this->assertSame([$this->listId], $this->getListIds($this->member));
		$this->assertSame([], $this->getListIds($this->outsider));
	}

	public function testMembersCanChangeTheList(): void {
		$this->assertOcsResponse(201, $this->share($this->group));
		$path = 'api/v1/lists/' . $this->listId;

		$list = $this->assertOcsResponse(200, $this->ocsRequest($this->member, 'PUT', $path, [
			RequestOptions::JSON => ['name' => 'Renamed by a member'],
		]));
		$this->assertIsArray($list);
		$this->assertSame('Renamed by a member', $list['name']);
		$this->assertOcsResponse(201, $this->ocsRequest($this->member, 'POST', $path . '/participants', [
			RequestOptions::JSON => ['displayName' => 'Alice', 'email' => 'alice@example.com'],
		]));
		$this->assertOcsResponse(201, $this->ocsRequest($this->member, 'POST', $path . '/participants', [
			RequestOptions::JSON => ['userId' => $this->owner],
		]));
		$this->assertCount(2, (array)$this->assertOcsResponse(200, $this->ocsRequest($this->owner, 'GET', $path . '/participants')));
		$this->assertOcsResponse(200, $this->ocsRequest($this->member, 'POST', $path . '/archive'));
		$this->assertOcsResponse(200, $this->ocsRequest($this->member, 'DELETE', $path . '/archive'));
	}

	public function testMembersCanImportExclusionsFromTheirOwnLists(): void {
		$this->assertOcsResponse(201, $this->share($this->group));
		$own = $this->assertOcsResponse(201, $this->ocsRequest($this->member, 'POST', 'api/v1/lists', [
			RequestOptions::JSON => ['name' => 'Own list'],
		]));
		$this->assertIsArray($own);

		$this->assertOcsResponse(400, $this->ocsRequest($this->member, 'POST', 'api/v1/lists/' . $this->listId . '/exclusions/import', [
			RequestOptions::JSON => ['sourceListId' => $own['id']],
		]));
		$this->assertOcsResponse(404, $this->ocsRequest($this->owner, 'POST', 'api/v1/lists/' . $this->listId . '/exclusions/import', [
			RequestOptions::JSON => ['sourceListId' => $own['id']],
		]));
	}

	public function testOnlyTheOwnerCanDeleteAndShare(): void {
		$this->assertOcsResponse(201, $this->share($this->group));
		$otherGroup = $this->createGroup([$this->member]);

		$this->assertOcsResponse(403, $this->share($otherGroup, $this->member));
		$this->assertOcsResponse(403, $this->ocsRequest($this->member, 'DELETE', 'api/v1/lists/' . $this->listId . '/shares/' . rawurlencode($this->group)));
		$this->assertOcsResponse(403, $this->ocsRequest($this->member, 'DELETE', 'api/v1/lists/' . $this->listId));
		$this->assertSame([$this->listId], $this->getListIds($this->member));
	}

	public function testOutsidersCanNotAccessTheList(): void {
		$this->assertOcsResponse(201, $this->share($this->group));
		$path = 'api/v1/lists/' . $this->listId;

		$this->assertOcsResponse(404, $this->ocsRequest($this->outsider, 'GET', $path));
		$this->assertOcsResponse(404, $this->ocsRequest($this->outsider, 'GET', $path . '/shares'));
		$this->assertOcsResponse(404, $this->ocsRequest($this->outsider, 'PUT', $path, [RequestOptions::JSON => ['name' => 'Taken']]));
		$this->assertOcsResponse(404, $this->share($this->createGroup([$this->outsider]), $this->outsider));
		$this->assertOcsResponse(404, $this->ocsRequest($this->outsider, 'DELETE', $path));
	}

	public function testShareRejectsUnknownGroupAndDuplicates(): void {
		$this->assertOcsResponse(400, $this->share('secret-santa-test-unknown-group'));
		$this->assertOcsResponse(201, $this->share($this->group));
		$this->assertOcsResponse(400, $this->share($this->group));
	}

	public function testUnshare(): void {
		$this->assertOcsResponse(201, $this->share($this->group));

		$this->assertNull($this->assertOcsResponse(200, $this->ocsRequest($this->owner, 'DELETE', 'api/v1/lists/' . $this->listId . '/shares/' . rawurlencode($this->group))));

		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($this->owner, 'GET', 'api/v1/lists/' . $this->listId . '/shares')));
		$this->assertOcsResponse(404, $this->ocsRequest($this->member, 'GET', 'api/v1/lists/' . $this->listId));
	}

	public function testDeletingTheOwnerKeepsSharedLists(): void {
		$this->assertOcsResponse(201, $this->share($this->group));

		$this->deleteUser($this->owner);

		$list = $this->assertOcsResponse(200, $this->ocsRequest($this->member, 'GET', 'api/v1/lists/' . $this->listId));
		$this->assertIsArray($list);
		$this->assertNull($list['owner']);
		$this->assertNull($list['ownerDisplayName']);
		$this->assertOcsResponse(200, $this->ocsRequest($this->member, 'PUT', 'api/v1/lists/' . $this->listId, [
			RequestOptions::JSON => ['name' => 'Without owner'],
		]));
		$this->assertOcsResponse(403, $this->ocsRequest($this->member, 'DELETE', 'api/v1/lists/' . $this->listId));
	}

	public function testDeletingTheGroupRemovesTheShare(): void {
		$this->assertOcsResponse(201, $this->share($this->group));

		$this->deleteGroup($this->group);

		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($this->owner, 'GET', 'api/v1/lists/' . $this->listId . '/shares')));
		$this->assertSame([], $this->getListIds($this->member));
	}
}
