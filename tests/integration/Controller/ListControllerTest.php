<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Integration\Controller;

use GuzzleHttp\RequestOptions;
use OCA\SecretSanta\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ListControllerTest extends IntegrationTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function createList(string $userId, string $name = 'Christmas'): array {
		$list = $this->assertOcsResponse(201, $this->ocsRequest($userId, 'POST', 'api/v1/lists', [
			RequestOptions::JSON => ['name' => $name],
		]));
		$this->assertIsArray($list);
		return $list;
	}

	public function testCreateList(): void {
		$alice = $this->createUser();

		$list = $this->createList($alice, ' Christmas ');

		$this->assertMatchesRegularExpression('/^\d+$/', $list['id']);
		$this->assertSame('Christmas', $list['name']);
		$this->assertNull($list['senderName']);
		$this->assertSame('Your Secret Santa partner', $list['mailSubject']);
		$this->assertStringContainsString('{name}', $list['mailBody']);
		$this->assertStringContainsString('{partner}', $list['mailBody']);
		$this->assertFalse($list['exclusionsImported']);
		$this->assertNull($list['archivedAt']);
		$this->assertSame($alice, $list['owner']);
		$this->assertSame($alice, $list['ownerDisplayName']);
		$this->assertSame(['id', 'owner', 'ownerDisplayName', 'name', 'senderName', 'mailSubject', 'mailBody', 'exclusionsImported', 'archivedAt'], array_keys($list));

		$this->assertSame($list, $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id'])));
		$this->assertSame([$list], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists')));
	}

	public function testCreateListRejectsEmptyName(): void {
		$alice = $this->createUser();

		$this->assertSame([], $this->assertOcsResponse(400, $this->ocsRequest($alice, 'POST', 'api/v1/lists', [
			RequestOptions::JSON => ['name' => ' '],
		])));
		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists')));
	}

	public function testUpdateList(): void {
		$alice = $this->createUser();
		$list = $this->createList($alice);

		$updated = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'PUT', 'api/v1/lists/' . $list['id'], [
			RequestOptions::JSON => ['senderName' => 'Santa', 'mailSubject' => 'Psst', 'mailBody' => 'Hi {name}, gift {partner}'],
		]));

		$this->assertSame([
			'id' => $list['id'],
			'owner' => $alice,
			'ownerDisplayName' => $alice,
			'name' => 'Christmas',
			'senderName' => 'Santa',
			'mailSubject' => 'Psst',
			'mailBody' => 'Hi {name}, gift {partner}',
			'exclusionsImported' => false,
			'archivedAt' => null,
		], $updated);
		$this->assertSame($updated, $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id'])));
	}

	public function testUpdateListRejectsInvalidValues(): void {
		$alice = $this->createUser();
		$list = $this->createList($alice);

		$this->assertOcsResponse(400, $this->ocsRequest($alice, 'PUT', 'api/v1/lists/' . $list['id'], [
			RequestOptions::JSON => ['name' => 'Party', 'mailBody' => ' '],
		]));
		$this->assertSame($list, $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id'])));
	}

	public function testArchiveList(): void {
		$alice = $this->createUser();
		$list = $this->createList($alice);
		$other = $this->createList($alice, 'Other');

		$archived = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/lists/' . $list['id'] . '/archive'));

		$this->assertIsArray($archived);
		$this->assertIsInt($archived['archivedAt']);
		$this->assertEqualsWithDelta(time(), $archived['archivedAt'], 60);
		$this->assertSame([...$list, 'archivedAt' => $archived['archivedAt']], $archived);
		$this->assertSame([$archived, $other], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists')));

		$unarchived = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'DELETE', 'api/v1/lists/' . $list['id'] . '/archive'));

		$this->assertSame($list, $unarchived);
		$this->assertSame($list, $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id'])));
	}

	public static function dataChangesOfArchivedLists(): array {
		return [
			'update list' => ['PUT', '', ['name' => 'Party']],
			'add participant' => ['POST', '/participants', ['displayName' => 'Bob', 'email' => 'bob@example.com']],
			'update participant' => ['PUT', '/participants/{participantId}', ['displayName' => 'Bob', 'email' => 'bob@example.com']],
			'remove participant' => ['DELETE', '/participants/{participantId}', []],
			'set exclusions' => ['PUT', '/participants/{participantId}/exclusions', ['receiverIds' => []]],
			'import exclusions' => ['POST', '/exclusions/import', ['sourceListId' => '{listId}']],
			'remove imported exclusions' => ['DELETE', '/exclusions/imported', []],
			'draw' => ['POST', '/draws', []],
			'notify' => ['POST', '/draws/154562972200341504/participants/{participantId}/notify', []],
		];
	}

	/**
	 * @param array<string, mixed> $body
	 */
	#[DataProvider('dataChangesOfArchivedLists')]
	public function testArchivedListsCanNotBeChanged(string $method, string $path, array $body): void {
		$alice = $this->createUser();
		$list = $this->createList($alice);
		$participant = $this->assertOcsResponse(201, $this->ocsRequest($alice, 'POST', 'api/v1/lists/' . $list['id'] . '/participants', [
			RequestOptions::JSON => ['displayName' => 'Alice', 'email' => 'alice@example.com'],
		]));
		$this->assertIsArray($participant);
		$archived = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/lists/' . $list['id'] . '/archive'));
		$replacements = ['{listId}' => $list['id'], '{participantId}' => $participant['id']];

		$response = $this->ocsRequest($alice, $method, 'api/v1/lists/' . $list['id'] . strtr($path, $replacements), [
			RequestOptions::JSON => array_map(static fn (mixed $value): mixed => is_string($value) ? strtr($value, $replacements) : $value, $body),
		]);

		$this->assertOcsResponse(400, $response);
		$this->assertSame('The list is archived. Unarchive it to change it.', json_decode((string)$response->getBody(), true)['ocs']['meta']['message']);
		$this->assertSame($archived, $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id'])));
		$this->assertSame([$participant], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id'] . '/participants')));
		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id'] . '/draws')));
	}

	public function testDeleteArchivedList(): void {
		$alice = $this->createUser();
		$list = $this->createList($alice);
		$this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/lists/' . $list['id'] . '/archive'));

		$this->assertNull($this->assertOcsResponse(200, $this->ocsRequest($alice, 'DELETE', 'api/v1/lists/' . $list['id'])));

		$this->assertOcsResponse(404, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id']));
	}

	public function testDeleteList(): void {
		$alice = $this->createUser();
		$list = $this->createList($alice);
		$other = $this->createList($alice, 'Other');

		$this->assertNull($this->assertOcsResponse(200, $this->ocsRequest($alice, 'DELETE', 'api/v1/lists/' . $list['id'])));

		$this->assertOcsResponse(404, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id']));
		$this->assertSame([$other], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists')));
	}

	public static function dataListsOfOtherUsers(): array {
		return [
			'get' => ['GET', '', []],
			'update' => ['PUT', '', ['name' => 'Mine now']],
			'archive' => ['POST', '/archive', []],
			'unarchive' => ['DELETE', '/archive', []],
			'delete' => ['DELETE', '', []],
		];
	}

	/**
	 * @param array<string, string> $body
	 */
	#[DataProvider('dataListsOfOtherUsers')]
	public function testListsOfOtherUsersAreNotFound(string $method, string $path, array $body): void {
		$alice = $this->createUser();
		$bob = $this->createUser();
		$list = $this->createList($alice);

		$this->assertOcsResponse(404, $this->ocsRequest($bob, $method, 'api/v1/lists/' . $list['id'] . $path, [RequestOptions::JSON => $body]));

		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($bob, 'GET', 'api/v1/lists')));
		$this->assertSame($list, $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id'])));
	}

	public function testInvalidIdIsNotFound(): void {
		$alice = $this->createUser();

		$this->assertOcsResponse(404, $this->ocsRequest($alice, 'GET', 'api/v1/lists/99999999999999999999'));
	}
}
