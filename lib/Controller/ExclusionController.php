<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Controller;

use InvalidArgumentException;
use OCA\SecretSanta\ResponseDefinitions;
use OCA\SecretSanta\Service\ExclusionService;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;

/**
 * @psalm-import-type SecretSantaExclusion from ResponseDefinitions
 *
 * @psalm-api Loaded by the router
 */
final class ExclusionController extends BaseOCSController {

	public function __construct(
		IRequest $request,
		ListService $listService,
		ParticipantService $participantService,
		?string $userId,
		private ExclusionService $exclusionService,
	) {
		parent::__construct($request, $listService, $participantService, $userId);
	}

	/**
	 * Get the partners the participants of a list must not draw
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, list<SecretSantaExclusion>, array{}>
	 * @throws OCSNotFoundException List not found or not owned by the current user
	 *
	 * 200: Exclusions returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/lists/{listId}/exclusions', requirements: ['listId' => '\d+'])]
	public function getExclusions(string $listId): DataResponse {
		$exclusions = $this->exclusionService->getExclusions($this->findList($listId));
		return new DataResponse(array_map($this->exclusionService->serialize(...), $exclusions));
	}

	/**
	 * Set the partners a participant must not draw
	 *
	 * Replaces the participants the participant must not draw.
	 * If `mutual` is set, the changes are applied in the other direction as well: newly excluded participants
	 * must not draw this participant, and participants that are no longer excluded may draw this participant again.
	 * Exclusions between other participants are left unchanged.
	 *
	 * @param string $listId The id of the list
	 * @param string $participantId The id of the participant
	 * @param list<string> $receiverIds The ids of the participants this participant must not draw
	 * @param bool $mutual Apply the changes in the other direction as well
	 * @return DataResponse<Http::STATUS_OK, list<SecretSantaExclusion>, array{}>
	 * @throws OCSBadRequestException A receiver is not a participant of the list or the participant themselves
	 * @throws OCSNotFoundException List or participant not found or the list is not owned by the current user
	 *
	 * 200: Exclusions changed, all exclusions of the list are returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/lists/{listId}/participants/{participantId}/exclusions', requirements: ['listId' => '\d+', 'participantId' => '\d+'])]
	public function setExclusions(string $listId, string $participantId, array $receiverIds, bool $mutual = true): DataResponse {
		$list = $this->findList($listId);
		$participant = $this->findParticipant($list, $participantId);
		try {
			$exclusions = $this->exclusionService->setExclusions($list, $participant, $receiverIds, $mutual);
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
		return new DataResponse(array_map($this->exclusionService->serialize(...), $exclusions));
	}
}
