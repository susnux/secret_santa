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
}
