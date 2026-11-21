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

final class ParticipantControllerTest extends IntegrationTestCase {

	private function createList(string $userId): string {
		$list = $this->assertOcsResponse(201, $this->ocsRequest($userId, 'POST', 'api/v1/lists', [
			RequestOptions::JSON => ['name' => 'Christmas'],
		]));
		$this->assertIsArray($list);
		return $list['id'];
	}

	/**
	 * @param array<string, string> $body
	 * @return array<string, mixed>
	 */
	private function addParticipant(string $userId, string $listId, array $body): array {
		$participant = $this->assertOcsResponse(201, $this->ocsRequest($userId, 'POST', 'api/v1/lists/' . $listId . '/participants', [
			RequestOptions::JSON => $body,
		]));
		$this->assertIsArray($participant);
		return $participant;
	}

	public function testAddGuest(): void {
		$alice = $this->createUser();
		$listId = $this->createList($alice);

		$guest = $this->addParticipant($alice, $listId, ['displayName' => 'Carol', 'email' => 'carol@example.com']);

		$this->assertMatchesRegularExpression('/^\d+$/', $guest['id']);
		$this->assertSame([
			'id' => $guest['id'],
			'userId' => null,
			'displayName' => 'Carol',
			'email' => 'carol@example.com',
			'hasEmail' => true,
		], $guest);
		$this->assertSame([$guest], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $listId . '/participants')));
	}

	public function testAddUserDoesNotRevealTheirEmail(): void {
		$alice = $this->createUser();
		$bob = $this->createUser();
		$listId = $this->createList($alice);

		$participant = $this->addParticipant($alice, $listId, ['userId' => $bob]);
		$this->assertSame([
			'id' => $participant['id'],
			'userId' => $bob,
			'displayName' => $bob,
			'email' => null,
			'hasEmail' => false,
		], $participant);

		$this->setEmail($bob, 'bob@example.com');
		$participants = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $listId . '/participants'));
		$this->assertSame([[...$participant, 'hasEmail' => true]], $participants);
	}

	public static function dataInvalidParticipants(): array {
		return [
			'unknown user' => [['userId' => 'secret-santa-test-unknown']],
			'no values' => [[]],
			'guest without email' => [['displayName' => 'Carol']],
			'invalid email' => [['displayName' => 'Carol', 'email' => 'carol']],
			'empty name' => [['displayName' => ' ', 'email' => 'carol@example.com']],
		];
	}

	/**
	 * @param array<string, string> $body
	 */
	#[DataProvider('dataInvalidParticipants')]
	public function testAddInvalidParticipant(array $body): void {
		$alice = $this->createUser();
		$listId = $this->createList($alice);

		$this->assertOcsResponse(400, $this->ocsRequest($alice, 'POST', 'api/v1/lists/' . $listId . '/participants', [
			RequestOptions::JSON => $body,
		]));
		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $listId . '/participants')));
	}

	public function testAddUserTwice(): void {
		$alice = $this->createUser();
		$bob = $this->createUser();
		$listId = $this->createList($alice);
		$this->addParticipant($alice, $listId, ['userId' => $bob]);

		$this->assertOcsResponse(400, $this->ocsRequest($alice, 'POST', 'api/v1/lists/' . $listId . '/participants', [
			RequestOptions::JSON => ['userId' => $bob],
		]));
	}

	public function testUpdateGuest(): void {
		$alice = $this->createUser();
		$listId = $this->createList($alice);
		$guest = $this->addParticipant($alice, $listId, ['displayName' => 'Carol', 'email' => 'carol@example.com']);

		$updated = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'PUT', 'api/v1/lists/' . $listId . '/participants/' . $guest['id'], [
			RequestOptions::JSON => ['displayName' => 'Caroline', 'email' => 'caroline@example.com'],
		]));

		$this->assertSame([...$guest, 'displayName' => 'Caroline', 'email' => 'caroline@example.com'], $updated);
		$this->assertSame([$updated], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $listId . '/participants')));
	}

	public function testUpdateUserIsRejected(): void {
		$alice = $this->createUser();
		$bob = $this->createUser();
		$listId = $this->createList($alice);
		$participant = $this->addParticipant($alice, $listId, ['userId' => $bob]);

		$this->assertOcsResponse(400, $this->ocsRequest($alice, 'PUT', 'api/v1/lists/' . $listId . '/participants/' . $participant['id'], [
			RequestOptions::JSON => ['displayName' => 'Bob', 'email' => 'bob@example.com'],
		]));
	}

	public function testDeleteParticipant(): void {
		$alice = $this->createUser();
		$listId = $this->createList($alice);
		$carol = $this->addParticipant($alice, $listId, ['displayName' => 'Carol', 'email' => 'carol@example.com']);
		$dave = $this->addParticipant($alice, $listId, ['displayName' => 'Dave', 'email' => 'dave@example.com']);

		$this->assertNull($this->assertOcsResponse(200, $this->ocsRequest($alice, 'DELETE', 'api/v1/lists/' . $listId . '/participants/' . $carol['id'])));

		$this->assertSame([$dave], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $listId . '/participants')));
	}

	public function testParticipantOfAnotherListIsNotFound(): void {
		$alice = $this->createUser();
		$listId = $this->createList($alice);
		$otherListId = $this->createList($alice);
		$carol = $this->addParticipant($alice, $otherListId, ['displayName' => 'Carol', 'email' => 'carol@example.com']);

		$path = 'api/v1/lists/' . $listId . '/participants/' . $carol['id'];
		$this->assertOcsResponse(404, $this->ocsRequest($alice, 'PUT', $path, [
			RequestOptions::JSON => ['displayName' => 'Caroline', 'email' => 'carol@example.com'],
		]));
		$this->assertOcsResponse(404, $this->ocsRequest($alice, 'DELETE', $path));
		$this->assertSame([$carol], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $otherListId . '/participants')));
	}

	public function testParticipantsOfListsOfOtherUsersAreNotFound(): void {
		$alice = $this->createUser();
		$bob = $this->createUser();
		$listId = $this->createList($alice);
		$carol = $this->addParticipant($alice, $listId, ['displayName' => 'Carol', 'email' => 'carol@example.com']);

		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'GET', 'api/v1/lists/' . $listId . '/participants'));
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'POST', 'api/v1/lists/' . $listId . '/participants', [
			RequestOptions::JSON => ['userId' => $bob],
		]));
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'DELETE', 'api/v1/lists/' . $listId . '/participants/' . $carol['id']));
		$this->assertSame([$carol], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $listId . '/participants')));
	}

	public function testDeletedUsersAreRemoved(): void {
		$alice = $this->createUser();
		$bob = $this->createUser();
		$listId = $this->createList($alice);
		$this->addParticipant($alice, $listId, ['userId' => $bob]);
		$carol = $this->addParticipant($alice, $listId, ['displayName' => 'Carol', 'email' => 'carol@example.com']);

		$this->deleteUser($bob);

		$this->assertSame([$carol], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/lists/' . $listId . '/participants')));
	}
}
