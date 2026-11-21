<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Integration\Controller;

use GuzzleHttp\RequestOptions;
use OCA\SecretSanta\Tests\Integration\IntegrationTestCase;

final class DrawControllerTest extends IntegrationTestCase {

	private string $alice;
	private string $listId;
	/** Random part of the email addresses of the guests, to find their emails in MailHog */
	private string $mailPrefix;
	/** @var array<string, string> name => participant id */
	private array $participants = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->alice = $this->createUser();
		$this->mailPrefix = 'secret-santa-' . bin2hex(random_bytes(6)) . '-';
		$list = $this->assertOcsResponse(201, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists', [
			RequestOptions::JSON => ['name' => 'Christmas'],
		]));
		$this->assertIsArray($list);
		$this->listId = $list['id'];
		// The text contains the partner in a fixed format, to find out the drawn partners in MailHog
		$this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'PUT', 'api/v1/lists/' . $this->listId, [
			RequestOptions::JSON => ['mailBody' => 'PARTNER: {partner}'],
		]));
	}

	/**
	 * @param list<string> $names
	 */
	private function addGuests(array $names): void {
		foreach ($names as $name) {
			$participant = $this->assertOcsResponse(201, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/participants', [
				RequestOptions::JSON => ['displayName' => $name, 'email' => $this->mailPrefix . $name . '@example.com'],
			]));
			$this->assertIsArray($participant);
			$this->participants[$name] = $participant['id'];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function draw(): array {
		$draw = $this->assertOcsResponse(201, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws'));
		$this->assertIsArray($draw);
		return $draw;
	}

	/**
	 * @return list<string> The partners sent to the participant, the oldest first
	 */
	private function getSentPartnersOf(string $name): array {
		$partners = [];
		foreach ($this->getMails($this->mailPrefix . $name . '@example.com') as $mail) {
			$this->assertSame(1, preg_match('/^PARTNER: ([a-z]+)\r?$/m', $mail, $matches), 'No partner in the email to ' . $name);
			$partners[] = $matches[1];
		}
		return $partners;
	}

	/**
	 * @return array<string, string> Name of the participant => name of their partner from the latest email
	 */
	private function getSentPartners(): array {
		$partners = [];
		foreach (array_keys($this->participants) as $name) {
			$sent = $this->getSentPartnersOf($name);
			$this->assertNotEmpty($sent, 'No email sent to ' . $name);
			$partners[$name] = $sent[array_key_last($sent)];
		}
		return $partners;
	}

	public function testDraw(): void {
		$this->addGuests(['carol', 'dave', 'erin']);

		$draw = $this->draw();

		$this->assertSame(['id', 'createdAt', 'outdated', 'notifications'], array_keys($draw));
		$this->assertFalse($draw['outdated']);
		$this->assertMatchesRegularExpression('/^\d+$/', $draw['id']);
		$this->assertEqualsWithDelta(time(), $draw['createdAt'], 300);
		$participantIds = array_column($draw['notifications'], 'participantId');
		sort($participantIds);
		$expectedIds = array_values($this->participants);
		sort($expectedIds);
		$this->assertSame($expectedIds, $participantIds);
		foreach ($draw['notifications'] as $notification) {
			$this->assertSame(['participantId', 'notifiedAt'], array_keys($notification), 'The partners are never returned');
		}

		$this->assertSame([$draw], $this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId . '/draws')));
	}

	public function testDrawSendsThePartners(): void {
		$this->addGuests(['carol', 'dave', 'erin', 'frank', 'grace', 'heidi']);
		$this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'PUT', 'api/v1/lists/' . $this->listId . '/participants/' . $this->participants['carol'] . '/exclusions', [
			RequestOptions::JSON => ['receiverIds' => [$this->participants['dave']]],
		]));

		$draw = $this->draw();
		$first = $this->getSentPartners();

		$this->assertNotContains(null, array_column($draw['notifications'], 'notifiedAt'));
		$receivers = array_values($first);
		sort($receivers);
		$this->assertSame(['carol', 'dave', 'erin', 'frank', 'grace', 'heidi'], $receivers);
		foreach ($first as $giver => $receiver) {
			$this->assertNotSame($giver, $receiver);
			$this->assertNotSame($giver, $first[$receiver], 'No two participants draw each other');
		}
		$this->assertNotSame('dave', $first['carol']);
		$this->assertNotSame('carol', $first['dave']);

		$this->draw();
		foreach ($this->getSentPartners() as $giver => $receiver) {
			$this->assertNotSame($first[$giver], $receiver, 'Nobody draws their partner of the previous draw');
		}
	}

	public function testDrawWithTooFewParticipants(): void {
		$this->addGuests(['carol', 'dave']);

		$this->assertOcsResponse(400, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws'));
		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId . '/draws')));
	}

	public function testDrawWithUserWithoutEmail(): void {
		$bob = $this->createUser();
		$this->addGuests(['carol', 'dave']);
		$this->assertOcsResponse(201, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/participants', [
			RequestOptions::JSON => ['userId' => $bob],
		]));

		$this->assertOcsResponse(400, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws'));
		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId . '/draws')));
	}

	public function testNotifyParticipant(): void {
		$this->addGuests(['carol', 'dave', 'erin']);
		$draw = $this->draw();

		$response = $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws/' . $draw['id'] . '/participants/' . $this->participants['dave'] . '/notify');

		if (getenv('MAILHOG_URL') === false) {
			$this->assertContains($response->getStatusCode(), [200, 503]);
			return;
		}
		$this->assertSame($draw['id'], $this->assertOcsResponse(200, $response)['id']);
		$partners = $this->getSentPartnersOf('dave');
		$this->assertCount(2, $partners);
		$this->assertSame($partners[0], $partners[1], 'The same partner is sent again');
	}

	public function testDrawIsRefusedIfItIsTheOnlyValidDraw(): void {
		$this->addGuests(['carol', 'dave', 'erin']);
		$draw = $this->draw();

		$this->assertOcsResponse(400, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws'));
		$this->assertSame([$draw], $this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId . '/draws')));
	}

	public function testRemovingAParticipantMarksTheDrawOutdated(): void {
		$this->addGuests(['carol', 'dave', 'erin', 'frank']);
		$draw = $this->draw();

		$this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'DELETE', 'api/v1/lists/' . $this->listId . '/participants/' . $this->participants['carol']));

		$draws = $this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId . '/draws'));
		$this->assertTrue($draws[0]['outdated']);
		$this->assertCount(3, $draws[0]['notifications'], 'Only carol is removed from the notifications, not who drew carol');
		foreach (['dave', 'erin', 'frank'] as $name) {
			$this->assertOcsResponse(400, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws/' . $draw['id'] . '/participants/' . $this->participants[$name] . '/notify'));
		}
	}

	public function testNotifyOnlyForTheLatestDraw(): void {
		$this->addGuests(['carol', 'dave', 'erin', 'frank', 'grace']);
		$first = $this->draw();
		$second = $this->draw();

		$this->assertOcsResponse(400, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws/' . $first['id'] . '/participants/' . $this->participants['dave'] . '/notify'));
		$this->assertSame(
			[$second['id'], $first['id']],
			array_column($this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId . '/draws')), 'id'),
		);
	}

	public function testNotifyUnknownDrawOrParticipant(): void {
		$this->addGuests(['carol', 'dave', 'erin']);
		$draw = $this->draw();

		$this->assertOcsResponse(404, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws/1/participants/' . $this->participants['dave'] . '/notify'));
		$this->assertOcsResponse(404, $this->ocsRequest($this->alice, 'POST', 'api/v1/lists/' . $this->listId . '/draws/' . $draw['id'] . '/participants/1/notify'));
	}

	public function testDrawsOfOtherUsersAreNotFound(): void {
		$bob = $this->createUser();
		$this->addGuests(['carol', 'dave', 'erin']);
		$draw = $this->draw();

		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'GET', 'api/v1/lists/' . $this->listId . '/draws'));
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'POST', 'api/v1/lists/' . $this->listId . '/draws'));
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'POST', 'api/v1/lists/' . $this->listId . '/draws/' . $draw['id'] . '/participants/' . $this->participants['dave'] . '/notify'));
		$this->assertCount(1, $this->assertOcsResponse(200, $this->ocsRequest($this->alice, 'GET', 'api/v1/lists/' . $this->listId . '/draws')));
	}
}
