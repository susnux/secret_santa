<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\SecretSanta\Db\Draw;
use OCA\SecretSanta\Db\DrawMapper;
use OCA\SecretSanta\Db\Exclusion;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\Pairing;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Db\Participant;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\Service\ExclusionService;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExclusionServiceTest extends TestCase {

	private const string OWNER = 'secret-santa-exclusion-test';

	private ListService $listService;
	private ParticipantService $participantService;
	private ExclusionService $service;
	private SecretSantaList $list;
	/** @var array<string, Participant> name => participant */
	private array $participants = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->listService = Server::get(ListService::class);
		$this->participantService = Server::get(ParticipantService::class);
		$this->service = Server::get(ExclusionService::class);
		$this->list = $this->listService->create(self::OWNER, 'Christmas');
		foreach (['alice', 'bob', 'carol', 'dave'] as $name) {
			$this->participants[$name] = $this->participantService->addGuest($this->list, $name, $name . '@example.com');
		}
	}

	#[\Override]
	protected function tearDown(): void {
		$this->listService->deleteByOwner(self::OWNER);
		parent::tearDown();
	}

	private function id(string $name): string {
		return $this->participants[$name]->getId();
	}

	/**
	 * @param list<string> $receivers Names of the participants the giver must not draw
	 * @return list<string> All exclusions as `giver>receiver`
	 */
	private function set(string $giver, array $receivers, bool $mutual): array {
		return $this->format($this->service->setExclusions(
			$this->list,
			$this->participants[$giver],
			array_map($this->id(...), $receivers),
			$mutual,
		));
	}

	/**
	 * @param list<Exclusion> $exclusions
	 * @return list<string> The exclusions as `giver>receiver`, sorted
	 */
	private function format(array $exclusions): array {
		$names = array_flip(array_map(static fn (Participant $participant): string => $participant->getId(), $this->participants));
		$formatted = array_map(static fn (Exclusion $exclusion): string => $names[$exclusion->giverId] . '>' . $names[$exclusion->receiverId], $exclusions);
		sort($formatted);
		return $formatted;
	}

	public function testSetOneWay(): void {
		$this->assertSame(['alice>bob', 'alice>carol'], $this->set('alice', ['bob', 'carol'], false));
		$this->assertSame(['alice>bob', 'alice>carol'], $this->format($this->service->getExclusions($this->list)));
	}

	public function testSetMutual(): void {
		$this->assertSame(['alice>bob', 'bob>alice'], $this->set('alice', ['bob'], true));
	}

	public function testSetReplacesExclusionsOfTheGiver(): void {
		$this->set('alice', ['bob', 'carol'], false);

		$this->assertSame(['alice>carol', 'alice>dave'], $this->set('alice', ['carol', 'dave'], false));
		$this->assertSame([], $this->set('alice', [], false));
	}

	public function testMutualRemovesTheOtherDirection(): void {
		$this->set('alice', ['bob', 'carol'], true);

		$this->assertSame(['alice>carol', 'carol>alice'], $this->set('alice', ['carol'], true));
	}

	public function testOneWayKeepsTheOtherDirection(): void {
		$this->set('alice', ['bob'], true);

		$this->assertSame(['bob>alice'], $this->set('alice', [], false));
	}

	public function testMutualOnlyAppliesChanges(): void {
		$this->set('dave', ['alice'], false);
		$this->set('bob', ['carol'], false);

		// dave>alice is kept, as dave was not excluded by alice before
		$this->assertSame(['alice>bob', 'bob>alice', 'bob>carol', 'dave>alice'], $this->set('alice', ['bob'], true));
	}

	public function testMutualKeepsExistingReverseExclusion(): void {
		$this->set('bob', ['alice'], false);

		$this->assertSame(['alice>bob', 'bob>alice'], $this->set('alice', ['bob'], true));
	}

	public function testDuplicateReceiversAreIgnored(): void {
		$this->assertSame(['alice>bob'], $this->set('alice', ['bob', 'bob'], false));
	}

	public static function dataInvalidReceivers(): array {
		return [
			'giver themselves' => ['self', 'A participant can not exclude themselves.'],
			'unknown participant' => ['unknown', 'Unknown participant.'],
			'participant of another list' => ['other list', 'Unknown participant.'],
			'not a string' => ['int', 'Unknown participant.'],
		];
	}

	#[DataProvider('dataInvalidReceivers')]
	public function testRejectsInvalidReceivers(string $receiver, string $message): void {
		$this->set('alice', ['bob'], true);
		$otherList = $this->listService->create(self::OWNER, 'Other');
		$receiverIds = [
			'self' => $this->id('alice'),
			'unknown' => '1',
			'other list' => $this->participantService->addGuest($otherList, 'Eve', 'eve@example.com')->getId(),
			'int' => (int)$this->id('carol'),
		];

		try {
			$this->service->setExclusions($this->list, $this->participants['alice'], [$this->id('carol'), $receiverIds[$receiver]], true);
			$this->fail('Expected an exception');
		} catch (\InvalidArgumentException $error) {
			$this->assertSame($message, $error->getMessage());
		}
		$this->assertSame(['alice>bob', 'bob>alice'], $this->format($this->service->getExclusions($this->list)));
	}

	public function testDeletingAParticipantDeletesTheirExclusions(): void {
		$this->set('alice', ['bob', 'carol'], true);
		$this->set('carol', ['alice', 'dave'], false);

		$this->participantService->delete($this->participants['bob']);
		unset($this->participants['bob']);

		$this->assertSame(['alice>carol', 'carol>alice', 'carol>dave'], $this->format($this->service->getExclusions($this->list)));
	}

	public function testDeletingAListDeletesTheExclusions(): void {
		$this->set('alice', ['bob'], true);

		$this->listService->delete($this->list);

		$this->assertSame([], $this->service->getExclusions($this->list));
	}

	/**
	 * Store a draw of a list with the given partners
	 *
	 * @param array<string, string> $partners Name of the giver => name of the receiver
	 */
	private function storeDraw(SecretSantaList $list, array $partners): void {
		$participants = [];
		foreach ($this->participantService->getParticipants($list) as $participant) {
			$participants[$participant->getDisplayName() ?? ''] = $participant->getId();
		}
		$draw = new Draw();
		$draw->setListId($list->getId());
		$draw->generateId();
		$draw = Server::get(DrawMapper::class)->insert($draw);
		foreach ($partners as $giver => $receiver) {
			Server::get(PairingMapper::class)->insert($list, $draw, new Pairing($participants[$giver], $participants[$receiver]));
		}
	}

	/**
	 * @return list<string> The imported exclusions as `giver>receiver`, sorted
	 */
	private function getImported(): array {
		return $this->format(Server::get(ExclusionMapper::class)->getExclusions($this->list, true));
	}

	/**
	 * A list of last year with the participants of this list, erin, and carol with a differently written email address
	 */
	private function createLastYear(): SecretSantaList {
		$lastYear = $this->listService->create(self::OWNER, 'Last year');
		foreach (['alice', 'bob', 'Carol', 'dave', 'erin'] as $name) {
			$this->participantService->addGuest($lastYear, strtolower($name), ' ' . $name . '@Example.com');
		}
		$this->storeDraw($lastYear, ['alice' => 'bob', 'bob' => 'carol', 'carol' => 'erin', 'dave' => 'alice', 'erin' => 'dave']);
		return $lastYear;
	}

	public function testImportDrawExcludesThePartnersOfMatchedParticipants(): void {
		$list = $this->service->importDraw($this->list, $this->createLastYear());

		$this->assertTrue($list->getExclusionsImported());
		$this->assertTrue($this->listService->get($this->list->getId(), self::OWNER)->getExclusionsImported());
		$this->assertSame(['alice>bob', 'bob>carol', 'dave>alice'], $this->getImported());
		$this->assertSame([], $this->service->getExclusions($this->list), 'Imported exclusions are never returned');
	}

	public function testImportAgainAddsTheNewParticipants(): void {
		$lastYear = $this->createLastYear();
		$this->service->importDraw($this->list, $lastYear);
		$this->participants['erin'] = $this->participantService->addGuest($this->list, 'erin', 'erin@example.com');

		$this->service->importDraw($this->list, $lastYear);

		$this->assertSame(['alice>bob', 'bob>carol', 'carol>erin', 'dave>alice', 'erin>dave'], $this->getImported());
	}

	public function testImportDrawOfTheListItself(): void {
		$this->storeDraw($this->list, ['alice' => 'bob', 'bob' => 'carol', 'carol' => 'dave', 'dave' => 'alice']);

		$this->service->importDraw($this->list, $this->list);

		$this->assertSame(['alice>bob', 'bob>carol', 'carol>dave', 'dave>alice'], $this->getImported());
	}

	public function testImportWithoutDrawIsRefused(): void {
		$lastYear = $this->listService->create(self::OWNER, 'Last year');

		try {
			$this->service->importDraw($this->list, $lastYear);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('The source list has no draw.', $error->getMessage());
		}
		$this->assertNotTrue($this->listService->get($this->list->getId(), self::OWNER)->getExclusionsImported());
	}

	public function testImportFromAnotherOwnerIsRefused(): void {
		$other = $this->listService->create(self::OWNER . '-other', 'Other');
		try {
			$this->storeDraw($other, []);

			$this->expectException(InvalidArgumentException::class);
			$this->service->importDraw($this->list, $other);
		} finally {
			$this->listService->deleteByOwner(self::OWNER . '-other');
		}
	}

	public function testExclusionsSetByHandLeaveImportedExclusionsAlone(): void {
		$this->service->importDraw($this->list, $this->createLastYear());

		// alice>bob is also imported, the mirrored exclusion is still set by hand
		$this->assertSame(['alice>bob', 'bob>alice'], $this->set('bob', ['alice'], true));
		$this->assertSame([], $this->set('bob', [], true));
		$this->assertSame(['alice>bob', 'bob>carol', 'dave>alice'], $this->getImported());
	}

	public function testRemoveImported(): void {
		$this->service->importDraw($this->list, $this->createLastYear());
		$this->set('alice', ['bob'], false);

		$list = $this->service->removeImported($this->list);

		$this->assertFalse($list->getExclusionsImported());
		$this->assertSame([], $this->getImported());
		$this->assertSame(['alice>bob'], $this->format($this->service->getExclusions($this->list)));
	}

	public function testDeletingAParticipantDeletesTheirImportedExclusions(): void {
		$this->service->importDraw($this->list, $this->createLastYear());

		$this->participantService->delete($this->participants['bob']);
		unset($this->participants['bob']);

		$this->assertSame(['dave>alice'], $this->getImported());
	}

	public function testSerialize(): void {
		$this->assertSame(
			['giverId' => '1', 'receiverId' => '2'],
			$this->service->serialize(new Exclusion('1', '2')),
		);
	}
}
