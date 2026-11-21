<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

use InvalidArgumentException;
use OCA\SecretSanta\Db\Exclusion;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\Participant;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\ResponseDefinitions;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * @psalm-import-type SecretSantaExclusion from ResponseDefinitions
 */
final class ExclusionService {
	use TTransactional;

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IDBConnection $db,
		private ExclusionMapper $exclusionMapper,
		private ParticipantMapper $participantMapper,
	) {
	}

	/**
	 * @return list<Exclusion>
	 */
	public function getExclusions(SecretSantaList $list): array {
		return $this->exclusionMapper->getExclusions($list);
	}

	/**
	 * Set the participants the giver must not draw.
	 *
	 * If `$mutual` is set, the changes are applied in the other direction as well:
	 * newly excluded participants must not draw the giver, and participants that are no longer excluded may draw the giver again.
	 *
	 * @param array<array-key, mixed> $receiverIds The ids of the participants the giver must not draw
	 * @return list<Exclusion> All exclusions of the list
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
			foreach ($this->exclusionMapper->getExclusions($list) as $exclusion) {
				if ($exclusion->giverId === $giverId) {
					$oldIds[] = $exclusion->receiverId;
				} elseif ($exclusion->receiverId === $giverId) {
					$reverseIds[] = $exclusion->giverId;
				}
			}

			foreach (array_diff($oldIds, $newIds) as $removedId) {
				$this->exclusionMapper->delete($giverId, $removedId);
				if ($mutual && in_array($removedId, $reverseIds, true)) {
					$this->exclusionMapper->delete($removedId, $giverId);
				}
			}
			foreach (array_diff($newIds, $oldIds) as $addedId) {
				$this->exclusionMapper->insert($list, $giverId, $addedId);
				if ($mutual && !in_array($addedId, $reverseIds, true)) {
					$this->exclusionMapper->insert($list, $addedId, $giverId);
				}
			}
		}, $this->db);

		return $this->exclusionMapper->getExclusions($list);
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
