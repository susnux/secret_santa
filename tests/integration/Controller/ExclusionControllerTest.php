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

final class ExclusionControllerTest extends IntegrationTestCase {

	private string $alice;
	private string $listId;
	/** @var array<string, string> name => participant id */
	private array $participants = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->alice = $this->createUser();
		$list = $this->assertOcsResponse(201, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists', [
			RequestOptions::JSON => ['name' => 'Christmas'],
		]));
		$this->assertIsArray($list);
		$this->listId = $list['id'];
		foreach (['carol', 'dave', 'erin'] as $name) {
			$participant = $this->assertOcsResponse(201, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/participants', [
				RequestOptions::JSON => ['displayName' => $name, 'email' => $name . '@example.com'],
			]));
			$this->assertIsArray($participant);
			$this->participants[$name] = $participant['id'];
		}
	}

	/**
	 * @param list<string> $receivers Names of the participants the giver must not draw
	 * @param array<string, mixed> $options Additional request values
	 */
	private function setExclusions(string $giver, array $receivers, array $options = []): mixed {
		return $this->ocsRequest($this->alice, 'PUT', 'api/v1/lists/' . $this->listId . '/participants/' . $this->participants[$giver] . '/exclusions', [
			RequestOptions::JSON => ['receiverIds' => array_map(fn (string $name): string => $this->participants[$name], $receivers), ...$options],
		]);
	}

	private function exclusion(string $giver, string $receiver): array {
		return ['giverId' => $this->participants[$giver], 'receiverId' => $this->participants[$receiver]];
	}

	/**
	 * @return list<array{giverId: string, receiverId: string}>
	 */
	private function getExclusions(): array {
		$exclusions = $this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId . '/exclusions'));
		$this->assertIsArray($exclusions);
		return $this->sorted($exclusions);
	}

	private function sorted(array $exclusions): array {
		usort($exclusions, static fn (array $a, array $b): int => [$a['giverId'], $a['receiverId']] <=> [$b['giverId'], $b['receiverId']]);
		return $exclusions;
	}

	public function testExclusionsAreEmptyByDefault(): void {
		$this->assertSame([], $this->getExclusions());
	}

	public function testSetMutualByDefault(): void {
		$exclusions = $this->assertOcsResponse(200, $this->setExclusions('carol', ['dave']));

		$expected = $this->sorted([$this->exclusion('carol', 'dave'), $this->exclusion('dave', 'carol')]);
		$this->assertSame($expected, $this->sorted($exclusions));
		$this->assertSame($expected, $this->getExclusions());
	}

	public function testSetOneWay(): void {
		$this->assertOcsResponse(200, $this->setExclusions('carol', ['dave', 'erin'], ['mutual' => false]));

		$this->assertSame($this->sorted([$this->exclusion('carol', 'dave'), $this->exclusion('carol', 'erin')]), $this->getExclusions());
	}

	public function testRemoveExclusions(): void {
		$this->assertOcsResponse(200, $this->setExclusions('carol', ['dave', 'erin']));

		$this->assertSame([], $this->assertOcsResponse(200, $this->setExclusions('carol', [])));
		$this->assertSame([], $this->getExclusions());
	}

	public static function dataInvalidReceivers(): array {
		return [
			'themselves' => ['carol'],
			'unknown participant' => ['1'],
		];
	}

	#[DataProvider('dataInvalidReceivers')]
	public function testInvalidReceivers(string $receiver): void {
		$receiverId = $this->participants[$receiver] ?? $receiver;

		$this->assertOcsResponse(400, $this->ocsRequest($this->alice, 'PUT', 'api/v1/lists/' . $this->listId . '/participants/' . $this->participants['carol'] . '/exclusions', [
			RequestOptions::JSON => ['receiverIds' => [$receiverId]],
		]));
		$this->assertSame([], $this->getExclusions());
	}

	public function testDeletingAParticipantDeletesTheirExclusions(): void {
		$this->assertOcsResponse(200, $this->setExclusions('carol', ['dave', 'erin']));

		$this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'DELETE', 'api/v1/lists/' . $this->listId . '/participants/' . $this->participants['dave']));

		$this->assertSame($this->sorted([$this->exclusion('carol', 'erin'), $this->exclusion('erin', 'carol')]), $this->getExclusions());
	}

	public function testExclusionsOfOtherUsersAreNotFound(): void {
		$bob = $this->createUser();
		$this->assertOcsResponse(200, $this->setExclusions('carol', ['dave']));

		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'GET', 'api/v1/lists/' . $this->listId . '/exclusions'));
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'PUT', 'api/v1/lists/' . $this->listId . '/participants/' . $this->participants['carol'] . '/exclusions', [
			RequestOptions::JSON => ['receiverIds' => []],
		]));
		$this->assertCount(2, $this->getExclusions());
	}

	public function testParticipantOfAnotherListIsNotFound(): void {
		$other = $this->assertOcsResponse(201, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists', [
			RequestOptions::JSON => ['name' => 'Other'],
		]));
		$this->assertIsArray($other);

		$this->assertOcsResponse(404, $this->ocsRequest($this->alice, 'PUT', 'api/v1/lists/' . $other['id'] . '/participants/' . $this->participants['carol'] . '/exclusions', [
			RequestOptions::JSON => ['receiverIds' => []],
		]));
	}

	private function createList(string $userId, string $name): string {
		$list = $this->assertOcsResponse(201, $this->ocsRequest($userId, 'POST', 'api/v1/lists', [
			RequestOptions::JSON => ['name' => $name],
		]));
		$this->assertIsArray($list);
		return $list['id'];
	}

	private function importExclusions(string $userId, string $listId, string $sourceListId): mixed {
		return $this->ocsRequest($userId, 'POST', 'api/v1/lists/' . $listId . '/exclusions/import', [
			RequestOptions::JSON => ['sourceListId' => $sourceListId],
		]);
	}

	private function drawResponse(): mixed {
		return $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws');
	}

	public function testImportExclusions(): void {
		// Sending the emails may fail without a mail server, the draw is stored anyway
		$this->assertOcsResponse(201, $this->drawResponse());

		$list = $this->assertOcsResponse(200, $this->importExclusions($this->alice, $this->listId, $this->listId));

		$this->assertIsArray($list);
		$this->assertSame($this->listId, $list['id']);
		$this->assertTrue($list['exclusionsImported']);
		$this->assertTrue($this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId))['exclusionsImported']);
		$this->assertSame([], $this->getExclusions(), 'Imported exclusions are never returned');
		// Three participants who must not draw their partner again have only one possible draw left
		$this->assertOcsResponse(400, $this->drawResponse());
	}

	public function testRemoveImportedExclusions(): void {
		$this->assertOcsResponse(201, $this->drawResponse());
		$this->assertOcsResponse(200, $this->importExclusions($this->alice, $this->listId, $this->listId));

		$list = $this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'DELETE', 'api/v1/lists/' . $this->listId . '/exclusions/imported'));

		$this->assertIsArray($list);
		$this->assertFalse($list['exclusionsImported']);
		$this->assertOcsResponse(201, $this->drawResponse());
	}

	public function testImportFromAnotherList(): void {
		$lastYear = $this->createList($this->alice, 'Last year');
		foreach (['carol', 'dave', 'erin'] as $name) {
			$this->assertOcsResponse(201, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $lastYear . '/participants', [
				RequestOptions::JSON => ['displayName' => ucfirst($name), 'email' => strtoupper($name) . '@example.com'],
			]));
		}
		$this->assertOcsResponse(201, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $lastYear . '/draws'));

		$this->assertOcsResponse(200, $this->importExclusions($this->alice, $this->listId, $lastYear));

		$this->assertOcsResponse(400, $this->drawResponse());
	}

	public function testImportWithoutDraw(): void {
		$lastYear = $this->createList($this->alice, 'Last year');

		$this->assertOcsResponse(400, $this->importExclusions($this->alice, $this->listId, $lastYear));
		$this->assertFalse($this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId))['exclusionsImported']);
	}

	public function testImportOfOtherUsersIsNotFound(): void {
		$bob = $this->createUser();
		$bobsList = $this->createList($bob, 'Bob');
		$this->assertOcsResponse(201, $this->drawResponse());

		$this->assertOcsResponse(404, $this->importExclusions($this->alice, $this->listId, $bobsList));
		$this->assertOcsResponse(404, $this->importExclusions($bob, $bobsList, $this->listId));
		$this->assertOcsResponse(404, $this->importExclusions($bob, $this->listId, $this->listId));
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'DELETE', 'api/v1/lists/' . $this->listId . '/exclusions/imported'));
		$this->assertOcsResponse(404, $this->importExclusions($this->alice, $this->listId, 'invalid'));
	}
}
