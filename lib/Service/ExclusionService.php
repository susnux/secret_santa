<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

use InvalidArgumentException;
use OCA\SecretSanta\Db\DrawMapper;
use OCA\SecretSanta\Db\Exclusion;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Db\Participant;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\ResponseDefinitions;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Exclusions are set by hand or imported from a draw.
 * Imported exclusions are only used for drawing, as they reveal the partners of that draw.
 *
 * @psalm-import-type SecretSantaExclusion from ResponseDefinitions
 */
final class ExclusionService {
	use TTransactional;

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IDBConnection $db,
		private IL10N $l10n,
		private ExclusionMapper $exclusionMapper,
		private ParticipantMapper $participantMapper,
		private ParticipantService $participantService,
		private SecretSantaListMapper $listMapper,
		private DrawMapper $drawMapper,
		private PairingMapper $pairingMapper,
	) {
	}

	/**
	 * @return list<Exclusion> The exclusions set by hand
	 */
	public function getExclusions(SecretSantaList $list): array {
		return $this->exclusionMapper->getExclusions($list, false);
	}

	/**
	 * Set the participants the giver must not draw.
	 *
	 * If `$mutual` is set, the changes are applied in the other direction as well:
	 * newly excluded participants must not draw the giver, and participants that are no longer excluded may draw the giver again.
	 * Imported exclusions are left unchanged.
	 *
	 * @param array<array-key, mixed> $receiverIds The ids of the participants the giver must not draw
	 * @return list<Exclusion> All exclusions of the list set by hand
	 * @throws InvalidArgumentException If a receiver is not a participant of the list or the giver themselves
	 */
	public function setExclusions(SecretSantaList $list, Participant $giver, array $receiverIds, bool $mutual): array {
		$giverId = $giver->getId();
		$participantIds = array_map(static fn (Participant $participant): string => $participant->getId(), $this->participantMapper->getParticipants($list));
		$newIds = [];
		foreach ($receiverIds as $receiverId) {
			if (!is_string($receiverId) || !in_array($receiverId, $participantIds, true)) {
				throw new InvalidArgumentException('Unknown participant.');
			}
			if ($receiverId === $giverId) {
				throw new InvalidArgumentException('A participant can not exclude themselves.');
			}
			$newIds[$receiverId] = $receiverId;
		}

		$this->atomic(function () use ($list, $giverId, $newIds, $mutual): void {
			$oldIds = [];
			$reverseIds = [];
			foreach ($this->getExclusions($list) as $exclusion) {
				if ($exclusion->giverId === $giverId) {
					$oldIds[] = $exclusion->receiverId;
				} elseif ($exclusion->receiverId === $giverId) {
					$reverseIds[] = $exclusion->giverId;
				}
			}

			foreach (array_diff($oldIds, $newIds) as $removedId) {
				$this->exclusionMapper->delete($giverId, $removedId, false);
				if ($mutual && in_array($removedId, $reverseIds, true)) {
					$this->exclusionMapper->delete($removedId, $giverId, false);
				}
			}
			foreach (array_diff($newIds, $oldIds) as $addedId) {
				$this->exclusionMapper->insert($list, $giverId, $addedId, false);
				if ($mutual && !in_array($addedId, $reverseIds, true)) {
					$this->exclusionMapper->insert($list, $addedId, $giverId, false);
				}
			}
		}, $this->db);

		return $this->getExclusions($list);
	}

	/**
	 * Exclude the partners of the latest draw of a list, so nobody draws the same partner again.
	 *
	 * The source can be the list itself. Participants of both lists are matched by `ParticipantService::matchParticipants()`.
	 * Nothing about the matched participants or partners is returned, only that exclusions were imported.
	 *
	 * @return SecretSantaList The list, marked as having imported exclusions
	 * @throws InvalidArgumentException If the lists have different owners
	 * @throws InvalidInputException If the partners of the source list have not been drawn yet
	 */
	public function importDraw(SecretSantaList $list, SecretSantaList $source): SecretSantaList {
		if ($source->getOwner() !== $list->getOwner()) {
			throw new InvalidArgumentException('Exclusions can only be imported from lists of the same owner.');
		}
		$draw = $this->drawMapper->getLatestDraw($source);
		if ($draw === null) {
			throw new InvalidInputException(
				'The source list has no draw.',
				$this->l10n->t('The partners of %s have not been drawn yet.', [$source->getName()]),
			);
		}

		$matches = $this->participantService->matchParticipants(
			$this->participantMapper->getParticipants($source),
			$this->participantMapper->getParticipants($list),
		);
		return $this->atomic(function () use ($list, $draw, $matches): SecretSantaList {
			$existing = [];
			foreach ($this->exclusionMapper->getExclusions($list, true) as $exclusion) {
				$existing[$exclusion->giverId . '>' . $exclusion->receiverId] = true;
			}
			foreach ($this->pairingMapper->getPairings($draw) as $pairing) {
				$giverId = $matches[$pairing->giverId] ?? null;
				$receiverId = $matches[$pairing->receiverId] ?? null;
				if ($giverId === null || $receiverId === null || isset($existing[$giverId . '>' . $receiverId])) {
					continue;
				}
				$this->exclusionMapper->insert($list, $giverId, $receiverId, true);
				$existing[$giverId . '>' . $receiverId] = true;
			}
			$list->setExclusionsImported(true);
			return $this->listMapper->update($list);
		}, $this->db);
	}

	/**
	 * Remove all exclusions imported from draws.
	 *
	 * @return SecretSantaList The list, marked as having no imported exclusions
	 */
	public function removeImported(SecretSantaList $list): SecretSantaList {
		return $this->atomic(function () use ($list): SecretSantaList {
			$this->exclusionMapper->deleteImported($list);
			$list->setExclusionsImported(false);
			return $this->listMapper->update($list);
		}, $this->db);
	}

	/**
	 * @return SecretSantaExclusion
	 */
	public function serialize(Exclusion $exclusion): array {
		return [
			'giverId' => $exclusion->giverId,
			'receiverId' => $exclusion->receiverId,
		];
	}
}
