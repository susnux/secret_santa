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
		$this->assertSame(['id', 'name', 'senderName', 'mailSubject', 'mailBody'], array_keys($list));

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
			'name' => 'Christmas',
			'senderName' => 'Santa',
			'mailSubject' => 'Psst',
			'mailBody' => 'Hi {name}, gift {partner}',
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
			'get' => ['GET', []],
			'update' => ['PUT', ['name' => 'Mine now']],
			'delete' => ['DELETE', []],
		];
	}

	/**
	 * @param array<string, string> $body
	 */
	#[DataProvider('dataListsOfOtherUsers')]
	public function testListsOfOtherUsersAreNotFound(string $method, array $body): void {
		$alice = $this->createUser();
		$bob = $this->createUser();
		$list = $this->createList($alice);

		$this->assertOcsResponse(404, $this->ocsRequest($bob, $method, 'api/v1/lists/' . $list['id'], [RequestOptions::JSON => $body]));

		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($bob, 'GET', 'api/v1/lists')));
		$this->assertSame($list, $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $list['id'])));
	}

	public function testInvalidIdIsNotFound(): void {
		$alice = $this->createUser();

		$this->assertOcsResponse(404, $this->ocsRequest($alice, 'GET', 'api/v1/lists/99999999999999999999'));
	}
}
