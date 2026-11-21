<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Service;

use OCA\SecretSanta\Db\Exclusion;
use OCA\SecretSanta\Db\Pairing;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\Service\DrawService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class DrawServiceTest extends TestCase {

	private function createService(?int $seed = null): DrawService {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));
		return new DrawService($l10n, new Randomizer($seed === null ? null : new Mt19937($seed)));
	}

	/**
	 * @return list<string> Snowflake like participant ids
	 */
	private static function participants(int $count): array {
		return array_map(static fn (int $index): string => (string)(154562972200341504 + $index), range(1, $count));
	}

	/**
	 * @param list<Pairing> $pairings
	 * @return array<string, string> "giver" => "receiver", with a prefix to keep numeric ids as string keys
	 */
	private static function toMap(array $pairings): array {
		$map = [];
		foreach ($pairings as $pairing) {
			$map['p' . $pairing->giverId] = 'p' . $pairing->receiverId;
		}
		return $map;
	}

	/**
	 * Assert that a draw follows all rules.
	 *
	 * @param list<string> $participantIds
	 * @param list<Pairing> $pairings
	 * @param list<Exclusion> $exclusions
	 */
	private function assertValidDraw(array $participantIds, array $pairings, array $exclusions = []): void {
		$this->assertSame($participantIds, array_map(static fn (Pairing $pairing): string => $pairing->giverId, $pairings), 'Every participant gives exactly one gift, in the order of the participants');
		$receivers = array_map(static fn (Pairing $pairing): string => $pairing->receiverId, $pairings);
		sort($receivers);
		$sortedParticipants = $participantIds;
		sort($sortedParticipants);
		$this->assertSame($sortedParticipants, $receivers, 'Every participant receives exactly one gift');

		$map = self::toMap($pairings);
		foreach ($map as $giver => $receiver) {
			$this->assertNotSame($giver, $receiver, 'Nobody draws themselves');
			$this->assertNotSame($giver, $map[$receiver], 'No two participants draw each other');
		}
		foreach ($exclusions as $forbidden) {
			$this->assertNotSame('p' . $forbidden->receiverId, $map['p' . $forbidden->giverId] ?? null, 'Forbidden pairings are not drawn');
		}
	}

	public static function dataSizes(): array {
		return array_map(static fn (int $count): array => [$count], [3 => 3, 4 => 4, 5 => 5, 8 => 8, 21 => 21, 100 => 100]);
	}

	#[DataProvider('dataSizes')]
	public function testDrawIsValid(int $count): void {
		$participantIds = self::participants($count);
		for ($seed = 0; $seed < 20; $seed++) {
			$this->assertValidDraw($participantIds, $this->createService($seed)->draw($participantIds, []));
		}
	}

	public function testDrawRespectsExclusions(): void {
		[$alice, $bob, $carol, $dave, $erin] = $participantIds = self::participants(5);
		$exclusions = [
			new Exclusion($alice, $bob),
			new Exclusion($bob, $alice),
			new Exclusion($carol, $dave),
			new Exclusion($carol, $erin),
			new Exclusion($alice, $carol),
			new Exclusion($carol, $bob),
			new Exclusion($dave, $erin),
		];

		for ($seed = 0; $seed < 50; $seed++) {
			$pairings = $this->createService($seed)->draw($participantIds, $exclusions);
			$this->assertValidDraw($participantIds, $pairings, $exclusions);
			// carol may only draw alice
			$this->assertSame('p' . $alice, self::toMap($pairings)['p' . $carol]);
		}
	}

	public function testThreeParticipantsCanDrawEitherDirection(): void {
		$participantIds = self::participants(3);
		$service = $this->createService();

		$draws = [];
		for ($i = 0; $i < 50; $i++) {
			$draws[json_encode(self::toMap($service->draw($participantIds, [])))] = true;
		}

		$this->assertCount(2, $draws, 'Both directions of the cycle are drawn');
	}

	public static function dataOnlyOneValidDraw(): array {
		// Participants are referenced by their position
		return [
			'three participants with an imported draw' => [3, [[0, 1], [1, 2], [2, 0]]],
			'three participants with one exclusion' => [3, [[0, 1]]],
			'four participants with an imported draw' => [4, [[0, 1], [1, 2], [2, 3], [3, 0]]],
			'exclusions leaving one cycle' => [4, [[0, 2], [0, 3], [1, 0], [1, 3], [2, 0], [2, 1], [3, 1], [3, 2]]],
		];
	}

	/**
	 * A draw is refused if it is the only valid one, as everybody could deduce it from the rules
	 *
	 * @param list<array{int, int}> $exclusions
	 */
	#[DataProvider('dataOnlyOneValidDraw')]
	public function testOnlyOneValidDraw(int $count, array $exclusions): void {
		$participantIds = self::participants($count);

		try {
			$this->createService()->draw(
				$participantIds,
				array_map(static fn (array $pair): Exclusion => new Exclusion($participantIds[$pair[0]], $participantIds[$pair[1]]), $exclusions),
			);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('Only one valid draw exists.', $error->getMessage());
			$this->assertSame('translated: Only one draw is possible with these participants and exclusions, so everybody could find out who drew whom. Add more participants or remove exclusions.', $error->getHint());
		}
	}

	public function testFiveParticipantsCanAvoidAnImportedDraw(): void {
		$participantIds = self::participants(5);
		$imported = array_map(static fn (int $index): Exclusion => new Exclusion($participantIds[$index], $participantIds[($index + 1) % 5]), range(0, 4));

		for ($seed = 0; $seed < 20; $seed++) {
			$this->assertValidDraw($participantIds, $this->createService($seed)->draw($participantIds, $imported), $imported);
		}
	}

	public function testUnknownParticipantsInExclusionsAreIgnored(): void {
		$participantIds = self::participants(3);

		$pairings = $this->createService(1)->draw($participantIds, [new Exclusion($participantIds[0], '1'), new Exclusion('2', $participantIds[1])]);

		$this->assertValidDraw($participantIds, $pairings);
	}

	public function testSameSeedGivesSameDraw(): void {
		$participantIds = self::participants(10);

		$this->assertEquals(
			$this->createService(42)->draw($participantIds, []),
			$this->createService(42)->draw($participantIds, []),
		);
	}

	public function testEveryAllowedPartnerCanBeDrawn(): void {
		[$alice, $bob, $carol, $dave] = $participantIds = self::participants(4);
		$service = $this->createService();

		$drawn = [];
		for ($i = 0; $i < 200; $i++) {
			$drawn[self::toMap($service->draw($participantIds, []))['p' . $alice]] = true;
		}

		ksort($drawn);
		$this->assertSame(['p' . $bob, 'p' . $carol, 'p' . $dave], array_keys($drawn));
	}

	public static function dataTooFewParticipants(): array {
		return [[0], [1], [2]];
	}

	#[DataProvider('dataTooFewParticipants')]
	public function testTooFewParticipants(int $count): void {
		try {
			$this->createService()->draw(self::participants($count), []);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('Too few participants.', $error->getMessage());
			$this->assertSame('translated: At least 3 participants are needed to draw the partners.', $error->getHint());
		}
	}

	public function testDuplicateParticipants(): void {
		[$alice, $bob, $carol] = self::participants(3);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Every participant can only take part once.');
		$this->createService()->draw([$alice, $bob, $carol, $alice], []);
	}

	public static function dataImpossibleDraws(): array {
		// Participants are referenced by their position
		return [
			'giver without receivers' => [4, [[0, 1], [0, 2], [0, 3]]],
			'receiver without givers' => [4, [[0, 3], [1, 3], [2, 3]]],
			'two givers with the same single receiver' => [4, [[0, 2], [0, 3], [1, 2], [1, 3]]],
			'an imported draw and an exclusion of three participants' => [3, [[0, 2], [0, 1], [1, 2], [2, 0]]],
			'only mutual pairs left' => [4, [[0, 2], [0, 3], [1, 2], [1, 3], [2, 0], [2, 1], [3, 0], [3, 1]]],
		];
	}

	/**
	 * @param list<array{int, int}> $exclusions
	 */
	#[DataProvider('dataImpossibleDraws')]
	public function testImpossibleDraws(int $count, array $exclusions): void {
		$participantIds = self::participants($count);

		try {
			$this->createService()->draw(
				$participantIds,
				array_map(static fn (array $pair): Exclusion => new Exclusion($participantIds[$pair[0]], $participantIds[$pair[1]]), $exclusions),
			);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('No valid draw exists.', $error->getMessage());
			$this->assertSame('translated: The partners can not be drawn with these exclusions. Remove some exclusions or add more participants.', $error->getHint());
		}
	}

	public function testLargeDrawWithManyExclusions(): void {
		$participantIds = self::participants(100);
		$randomizer = new Randomizer(new Mt19937(7));
		$exclusions = [];
		foreach ($participantIds as $index => $giverId) {
			foreach ($randomizer->pickArrayKeys($participantIds, 30) as $receiver) {
				if ($receiver !== $index) {
					$exclusions[] = new Exclusion($giverId, $participantIds[$receiver]);
				}
			}
			$exclusions[] = new Exclusion($giverId, $participantIds[($index + 1) % 100]);
		}

		$start = hrtime(true);
		$pairings = $this->createService(3)->draw($participantIds, $exclusions);

		$this->assertLessThan(2_000_000_000, hrtime(true) - $start, 'The draw takes less than two seconds');
		$this->assertValidDraw($participantIds, $pairings, $exclusions);
	}
}
