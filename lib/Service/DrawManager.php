<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

use InvalidArgumentException;
use OCA\SecretSanta\Db\Draw;
use OCA\SecretSanta\Db\DrawMapper;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\Pairing;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Db\Participant;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Draws the partners of a list, stores the draw and sends the participants their partner.
 *
 * The drawn partners are only sent to the participants, they are never returned or logged.
 *
 * @psalm-import-type SecretSantaDraw from ResponseDefinitions
 */
final class DrawManager {
	use TTransactional;

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private DrawService $drawService,
		private NotificationService $notificationService,
		private ParticipantService $participantService,
		private ExclusionMapper $exclusionMapper,
		private DrawMapper $drawMapper,
		private PairingMapper $pairingMapper,
	) {
	}

	/**
	 * @return list<Draw> The draws of the list, the latest first
	 */
	public function getDraws(SecretSantaList $list): array {
		return $this->drawMapper->getDraws($list);
	}

	/**
	 * @throws DoesNotExistException If the draw does not exist or is not a draw of the list
	 */
	public function getDraw(SecretSantaList $list, string $id): Draw {
		return $this->drawMapper->getDraw($list, $id);
	}

	/**
	 * Draw the partners of all participants and send every participant an email with their partner.
	 *
	 * Nobody gets their partner of the previous draw of the list.
	 * The draw is stored even if some emails can not be sent, they can be sent again with `notify()`.
	 *
	 * @throws InvalidInputException If a participant has no email address, there are too few participants or no valid draw exists
	 */
	public function draw(SecretSantaList $list): Draw {
		$participants = $this->participantService->getParticipants($list);
		$recipients = [];
		foreach ($participants as $participant) {
			$recipients[$participant->getId()] = $this->participantService->getRecipient($participant);
		}

		$previous = $this->drawMapper->getLatestDraw($list);
		$pairings = $this->drawService->draw(
			array_map(static fn (Participant $participant): string => $participant->getId(), $participants),
			$this->exclusionMapper->getExclusions($list),
			$previous === null ? [] : $this->pairingMapper->getPairings($previous),
		);

		$draw = $this->atomic(function () use ($list, $pairings): Draw {
			$draw = new Draw();
			$draw->setListId($list->getId());
			$draw->generateId();
			$draw = $this->drawMapper->insert($draw);
			foreach ($pairings as $pairing) {
				$this->pairingMapper->insert($list, $draw, $pairing);
			}
			return $draw;
		}, $this->db);

		foreach ($pairings as $pairing) {
			try {
				$this->notificationService->send($list, $recipients[$pairing->giverId], $recipients[$pairing->receiverId]);
				$this->pairingMapper->setNotified($draw, $pairing->giverId, $this->timeFactory->getTime());
			} catch (RuntimeException $error) {
				$this->logger->error('Could not send the email of a draw to a participant.', ['exception' => $error]);
			}
		}
		return $draw;
	}

	/**
	 * Send the email with their partner to a participant of the latest draw again.
	 *
	 * @throws InvalidArgumentException If the draw is not the latest draw of the list or the participant did not take part in it
	 * @throws InvalidInputException If the participant or their partner has no email address
	 * @throws RuntimeException If the email could not be sent
	 */
	public function notify(SecretSantaList $list, Draw $draw, Participant $giver): void {
		if ($this->drawMapper->getLatestDraw($list)?->getId() !== $draw->getId()) {
			throw new InvalidArgumentException('Only the emails of the latest draw can be sent again.');
		}

		$pairing = $this->findPairing($draw, $giver);
		try {
			$receiver = $this->participantService->get($list, $pairing->receiverId);
		} catch (DoesNotExistException $error) {
			throw new InvalidArgumentException('The partner of the participant was removed.', previous: $error);
		}
		$this->notificationService->send($list, $this->participantService->getRecipient($giver), $this->participantService->getRecipient($receiver));
		$this->pairingMapper->setNotified($draw, $giver->getId(), $this->timeFactory->getTime());
	}

	/**
	 * @return SecretSantaDraw
	 */
	public function serialize(Draw $draw): array {
		return [
			'id' => $draw->getId(),
			'createdAt' => $draw->getCreatedAt()?->getTimestamp() ?? 0,
			'notifications' => array_map(static fn (Pairing $pairing): array => [
				'participantId' => $pairing->giverId,
				'notifiedAt' => $pairing->notifiedAt,
			], $this->pairingMapper->getPairings($draw)),
		];
	}

	/**
	 * @throws InvalidArgumentException If the participant did not take part in the draw
	 */
	private function findPairing(Draw $draw, Participant $giver): Pairing {
		foreach ($this->pairingMapper->getPairings($draw) as $pairing) {
			if ($pairing->giverId === $giver->getId()) {
				return $pairing;
			}
		}
		throw new InvalidArgumentException('The participant did not take part in the draw.');
	}
}
