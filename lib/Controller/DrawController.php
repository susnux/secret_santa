<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Controller;

use InvalidArgumentException;
use OCA\SecretSanta\Db\Draw;
use OCA\SecretSanta\Db\SecretSantaList as SecretSantaListEntity;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\ResponseDefinitions;
use OCA\SecretSanta\Service\DrawManager;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use RuntimeException;

/**
 * @psalm-import-type SecretSantaDraw from ResponseDefinitions
 *
 * @psalm-api Loaded by the router
 */
final class DrawController extends BaseOCSController {

	public function __construct(
		IRequest $request,
		ListService $listService,
		ParticipantService $participantService,
		?string $userId,
		private DrawManager $drawManager,
		private IL10N $l10n,
	) {
		parent::__construct($request, $listService, $participantService, $userId);
	}

	/**
	 * Get the draws of a list
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, list<SecretSantaDraw>, array{}>
	 * @throws OCSNotFoundException List not found or not owned by the current user
	 *
	 * 200: Draws returned, the latest first
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/lists/{listId}/draws', requirements: ['listId' => '\d+'])]
	public function getDraws(string $listId): DataResponse {
		$list = $this->findList($listId);
		return new DataResponse(array_map(
			fn (Draw $draw): array => $this->drawManager->serialize($list, $draw),
			$this->drawManager->getDraws($list),
		));
	}

	/**
	 * Draw the partners and send every participant an email with their partner
	 *
	 * Nobody draws themselves or an excluded partner, including the partners of imported draws,
	 * and no two participants draw each other.
	 * The draw is stored even if some emails could not be sent, they have no `notifiedAt` and can be sent again.
	 * The drawn partners are never returned.
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_CREATED, SecretSantaDraw, array{}>
	 * @throws OCSBadRequestException A participant has no email address, too few participants, not at least two valid draws exist or the list is archived
	 * @throws OCSNotFoundException List not found or not owned by the current user
	 *
	 * 201: Partners drawn
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/lists/{listId}/draws', requirements: ['listId' => '\d+'])]
	public function createDraw(string $listId): DataResponse {
		$list = $this->findEditableList($listId);
		try {
			$draw = $this->drawManager->draw($list);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		}
		return new DataResponse($this->drawManager->serialize($list, $draw), Http::STATUS_CREATED);
	}

	/**
	 * Send the email with their partner to a participant again
	 *
	 * Only possible for the latest draw of the list, if it is not outdated.
	 *
	 * @param string $listId The id of the list
	 * @param string $drawId The id of the draw
	 * @param string $participantId The id of the participant
	 * @return DataResponse<Http::STATUS_OK, SecretSantaDraw, array{}>|DataResponse<Http::STATUS_SERVICE_UNAVAILABLE, array{message: string}, array{}>
	 * @throws OCSBadRequestException Not the latest draw, the draw is outdated, the participant did not take part in it or has no email address, or the list is archived
	 * @throws OCSNotFoundException List, draw or participant not found or the list is not owned by the current user
	 *
	 * 200: Email sent
	 * 503: The email could not be sent
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 3600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/lists/{listId}/draws/{drawId}/participants/{participantId}/notify', requirements: ['listId' => '\d+', 'drawId' => '\d+', 'participantId' => '\d+'])]
	public function notifyParticipant(string $listId, string $drawId, string $participantId): DataResponse {
		$list = $this->findEditableList($listId);
		$draw = $this->findDraw($list, $drawId);
		$participant = $this->findParticipant($list, $participantId);
		try {
			$this->drawManager->notify($list, $draw, $participant);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		} catch (RuntimeException) {
			return new DataResponse(['message' => $this->l10n->t('The email could not be sent.')], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		return new DataResponse($this->drawManager->serialize($list, $draw));
	}

	/**
	 * @throws OCSNotFoundException If the draw is not a draw of the list
	 */
	private function findDraw(SecretSantaListEntity $list, string $drawId): Draw {
		try {
			return $this->drawManager->getDraw($list, $drawId);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}
}
