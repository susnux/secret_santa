<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Controller;

use InvalidArgumentException;
use OCA\SecretSanta\Exception\InvalidInputException;
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
 * @psalm-import-type SecretSantaList from ResponseDefinitions
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
	 * Only the exclusions set by hand are returned, exclusions imported from draws are never returned.
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, list<SecretSantaExclusion>, array{}>
	 * @throws OCSNotFoundException List not found or not accessible by the current user
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
	 * @throws OCSBadRequestException A receiver is not a participant of the list or the participant themselves, or the list is archived
	 * @throws OCSNotFoundException List or participant not found or the list is not accessible by the current user
	 *
	 * 200: Exclusions changed, all exclusions of the list are returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/lists/{listId}/participants/{participantId}/exclusions', requirements: ['listId' => '\d+', 'participantId' => '\d+'])]
	public function setExclusions(string $listId, string $participantId, array $receiverIds, bool $mutual = true): DataResponse {
		$list = $this->findEditableList($listId);
		$participant = $this->findParticipant($list, $participantId);
		try {
			$exclusions = $this->exclusionService->setExclusions($list, $participant, $receiverIds, $mutual);
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
		return new DataResponse(array_map($this->exclusionService->serialize(...), $exclusions));
	}

	/**
	 * Import the partners of the latest draw of a list as exclusions
	 *
	 * Nobody draws the partner they drew in the latest draw of the source list, which can be the list itself.
	 * Participants of both lists are matched by their Nextcloud account or their email address.
	 * The imported exclusions are only used for drawing, they are never returned as they would reveal the partners.
	 * Importing again adds the partners of participants added in the meantime.
	 *
	 * @param string $listId The id of the list
	 * @param string $sourceListId The id of the list whose latest draw is imported
	 * @return DataResponse<Http::STATUS_OK, SecretSantaList, array{}>
	 * @throws OCSBadRequestException The partners of the source list have not been drawn yet or the list is archived
	 * @throws OCSNotFoundException List or source list not found or not accessible by the current user
	 *
	 * 200: Exclusions imported, the list is returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/lists/{listId}/exclusions/import', requirements: ['listId' => '\d+'])]
	public function importExclusions(string $listId, string $sourceListId): DataResponse {
		$list = $this->findEditableList($listId);
		$source = $this->findList($sourceListId);
		try {
			$list = $this->exclusionService->importDraw($list, $source);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		}
		return new DataResponse($this->listService->serialize($list));
	}

	/**
	 * Remove the exclusions imported from draws
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, SecretSantaList, array{}>
	 * @throws OCSBadRequestException The list is archived
	 * @throws OCSNotFoundException List not found or not accessible by the current user
	 *
	 * 200: Imported exclusions removed, the list is returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/lists/{listId}/exclusions/imported', requirements: ['listId' => '\d+'])]
	public function removeImportedExclusions(string $listId): DataResponse {
		$list = $this->exclusionService->removeImported($this->findEditableList($listId));
		return new DataResponse($this->listService->serialize($list));
	}
}
