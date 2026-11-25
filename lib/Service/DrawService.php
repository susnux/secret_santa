<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

use InvalidArgumentException;
use OCA\SecretSanta\Db\Exclusion;
use OCA\SecretSanta\Db\Pairing;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCP\IL10N;
use Random\Randomizer;

/**
 * Draws the partner every participant gives a gift to.
 *
 * Nobody draws themselves or a partner they must not draw, and no two participants draw each other.
 * A draw is only made if another valid draw exists, otherwise the partners could be deduced from these rules,
 * e.g. three participants could deduce the partners of the draw that their exclusions were imported from.
 */
final class DrawService {

	/** Minimum number of participants, as two participants could only draw each other */
	public const int MIN_PARTICIPANTS = 3;

	/** Maximum number of partial draws tried, bounds the run time if no valid draw exists */
	private const int MAX_STEPS = 5_000;

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IL10N $l10n,
		private Randomizer $randomizer = new Randomizer(),
	) {
	}

	/**
	 * Draw a partner for every participant.
	 *
	 * @param list<string> $participantIds
	 * @param list<Exclusion> $exclusions Partners participants must not draw
	 * @return list<Pairing> One pairing for every participant
	 * @throws InvalidInputException If there are too few participants or not at least two valid draws exist
	 * @throws InvalidArgumentException If a participant is listed twice
	 */
	public function draw(array $participantIds, #[\SensitiveParameter] array $exclusions): array {
		if (count(array_unique($participantIds)) !== count($participantIds)) {
			throw new InvalidArgumentException('Every participant can only take part once.');
		}
		if (count($participantIds) < self::MIN_PARTICIPANTS) {
			throw new InvalidInputException(
				'Too few participants.',
				$this->l10n->t('At least %d participants are needed to draw the partners.', [self::MIN_PARTICIPANTS]),
			);
		}

		// Participants are referenced by their position, as numeric ids would be converted to integers as array keys
		$positions = array_flip($participantIds);
		$forbidden = [];
		foreach ($exclusions as $exclusion) {
			if (isset($positions[$exclusion->giverId], $positions[$exclusion->receiverId])) {
				$forbidden[$positions[$exclusion->giverId]][$positions[$exclusion->receiverId]] = true;
			}
		}

		/** @var array<int, list<int>> $candidates The allowed receivers of every giver, in random order */
		$candidates = [];
		foreach ($this->randomizer->shuffleArray(array_keys($participantIds)) as $giver) {
			$receivers = array_filter(
				array_keys($participantIds),
				static fn (int $receiver): bool => $receiver !== $giver && !isset($forbidden[$giver][$receiver]),
			);
			$candidates[$giver] = $this->randomizer->shuffleArray(array_values($receivers));
		}

		$receiverOf = [];
		$giverOf = [];
		$steps = 0;
		if (!$this->hasPerfectMatching($candidates) || !$this->search($candidates, $receiverOf, $giverOf, $steps)) {
			throw new InvalidInputException(
				'No valid draw exists.',
				$this->l10n->t('The partners can not be drawn with these exclusions. Remove some exclusions or add more participants.'),
			);
		}

		if (!$this->hasOtherDraw($candidates, $receiverOf)) {
			throw new InvalidInputException(
				'Only one valid draw exists.',
				$this->l10n->t('Only one draw is possible with these participants and exclusions, so everybody could find out who drew whom. Add more participants or remove exclusions.'),
			);
		}

		$pairings = [];
		foreach ($participantIds as $giver => $giverId) {
			$pairings[] = new Pairing($giverId, $participantIds[$receiverOf[$giver]]);
		}
		return $pairings;
	}

	/**
	 * Check if another valid draw exists, which gives at least one giver a different receiver.
	 *
	 * Gives up after the maximum number of steps, treating the draw as the only one.
	 *
	 * @param array<int, list<int>> $candidates
	 * @param array<int, int> $receiverOf giver => receiver of the found draw
	 */
	private function hasOtherDraw(array $candidates, array $receiverOf): bool {
		$steps = 0;
		foreach (array_keys($candidates) as $giver) {
			$others = $candidates;
			$others[$giver] = array_values(array_filter(
				$candidates[$giver],
				static fn (int $receiver): bool => $receiver !== $receiverOf[$giver],
			));
			$otherReceiverOf = [];
			$otherGiverOf = [];
			if ($this->hasPerfectMatching($others) && $this->search($others, $otherReceiverOf, $otherGiverOf, $steps)) {
				return true;
			}
			if ($steps > self::MAX_STEPS) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Check if every giver can get a different receiver, ignoring that two participants must not draw each other.
	 *
	 * Detects most impossible draws without trying all combinations (Kuhn's algorithm for bipartite matchings).
	 *
	 * @param array<int, list<int>> $candidates
	 */
	private function hasPerfectMatching(array $candidates): bool {
		$giverOf = [];
		foreach (array_keys($candidates) as $giver) {
			$visited = [];
			if (!$this->findAugmentingPath($giver, $candidates, $giverOf, $visited)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int, list<int>> $candidates
	 * @param array<int, int> $giverOf receiver => giver of the current matching
	 * @param array<int, true> $visited Receivers already visited while searching this path
	 */
	private function findAugmentingPath(int $giver, array $candidates, array &$giverOf, array &$visited): bool {
		foreach ($candidates[$giver] as $receiver) {
			if (isset($visited[$receiver])) {
				continue;
			}
			$visited[$receiver] = true;
			if (!isset($giverOf[$receiver]) || $this->findAugmentingPath($giverOf[$receiver], $candidates, $giverOf, $visited)) {
				$giverOf[$receiver] = $giver;
				return true;
			}
		}
		return false;
	}

	/**
	 * Complete a partial draw by backtracking, continuing with the giver with the fewest remaining receivers.
	 *
	 * @param array<int, list<int>> $candidates
	 * @param array<int, int> $receiverOf giver => receiver of the partial draw
	 * @param array<int, int> $giverOf receiver => giver of the partial draw
	 * @param int $steps Number of partial draws tried so far
	 * @return bool If the draw was completed
	 */
	private function search(array $candidates, array &$receiverOf, array &$giverOf, int &$steps): bool {
		if (count($receiverOf) === count($candidates)) {
			return true;
		}
		if (++$steps > self::MAX_STEPS) {
			return false;
		}

		$nextGiver = null;
		$nextReceivers = [];
		foreach ($candidates as $giver => $receivers) {
			if (isset($receiverOf[$giver])) {
				continue;
			}
			$available = array_values(array_filter(
				$receivers,
				static fn (int $receiver): bool => !isset($giverOf[$receiver]) && ($receiverOf[$receiver] ?? null) !== $giver,
			));
			if ($available === []) {
				return false;
			}
			if ($nextGiver === null || count($available) < count($nextReceivers)) {
				$nextGiver = $giver;
				$nextReceivers = $available;
			}
		}
		if ($nextGiver === null) {
			return false;
		}

		foreach ($nextReceivers as $receiver) {
			$receiverOf[$nextGiver] = $receiver;
			$giverOf[$receiver] = $nextGiver;
			if ($this->search($candidates, $receiverOf, $giverOf, $steps)) {
				return true;
			}
			unset($receiverOf[$nextGiver], $giverOf[$receiver]);
		}
		return false;
	}
}
