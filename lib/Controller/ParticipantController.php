<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Controller;

use InvalidArgumentException;
use OCA\SecretSanta\AppInfo\Application;
use OCA\SecretSanta\Db\Participant;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\ResponseDefinitions;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type SecretSantaParticipant from ResponseDefinitions
 *
 * @psalm-api Loaded by the router
 */
final class ParticipantController extends OCSController {

	public function __construct(
		IRequest $request,
		private ListService $listService,
		private ParticipantService $participantService,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Get the participants of a list
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, list<SecretSantaParticipant>, array{}>
	 * @throws OCSNotFoundException List not found or not owned by the current user
	 *
	 * 200: Participants returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/lists/{listId}/participants', requirements: ['listId' => '\d+'])]
	public function getParticipants(string $listId): DataResponse {
		$participants = $this->participantService->getParticipants($this->findList($listId));
		return new DataResponse(array_map($this->participantService->serialize(...), $participants));
	}

	/**
	 * Add a participant to a list
	 *
	 * Either `userId` is set to add a Nextcloud user, or `displayName` and `email` to add a guest.
	 *
	 * @param string $listId The id of the list
	 * @param ?string $userId The Nextcloud user to add
	 * @param ?string $displayName The name of the guest to add (up to 255 characters)
	 * @param ?string $email The email address of the guest to add (up to 255 characters)
	 * @return DataResponse<Http::STATUS_CREATED, SecretSantaParticipant, array{}>
	 * @throws OCSBadRequestException Unknown user, invalid values, already a participant or too many participants
	 * @throws OCSNotFoundException List not found or not owned by the current user
	 *
	 * 201: Participant added
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/lists/{listId}/participants', requirements: ['listId' => '\d+'])]
	public function addParticipant(string $listId, ?string $userId = null, ?string $displayName = null, ?string $email = null): DataResponse {
		$list = $this->findList($listId);
		try {
			if ($userId !== null) {
				$participant = $this->participantService->addUser($list, $userId);
			} elseif ($displayName !== null && $email !== null) {
				$participant = $this->participantService->addGuest($list, $displayName, $email);
			} else {
				throw new InvalidArgumentException('Either the user id or the name and email address are required.');
			}
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
		return new DataResponse($this->participantService->serialize($participant), Http::STATUS_CREATED);
	}

	/**
	 * Change the name and email address of a guest
	 *
	 * @param string $listId The id of the list
	 * @param string $participantId The id of the participant
	 * @param string $displayName The name of the guest (up to 255 characters)
	 * @param string $email The email address of the guest (up to 255 characters)
	 * @return DataResponse<Http::STATUS_OK, SecretSantaParticipant, array{}>
	 * @throws OCSBadRequestException Invalid values or the participant is a Nextcloud user
	 * @throws OCSNotFoundException List or participant not found or the list is not owned by the current user
	 *
	 * 200: Participant changed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/lists/{listId}/participants/{participantId}', requirements: ['listId' => '\d+', 'participantId' => '\d+'])]
	public function updateParticipant(string $listId, string $participantId, string $displayName, string $email): DataResponse {
		$list = $this->findList($listId);
		$participant = $this->findParticipant($list, $participantId);
		try {
			$participant = $this->participantService->updateGuest($list, $participant, $displayName, $email);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
		return new DataResponse($this->participantService->serialize($participant));
	}

	/**
	 * Remove a participant from a list
	 *
	 * @param string $listId The id of the list
	 * @param string $participantId The id of the participant
	 * @return DataResponse<Http::STATUS_OK, null, array{}>
	 * @throws OCSNotFoundException List or participant not found or the list is not owned by the current user
	 *
	 * 200: Participant removed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/lists/{listId}/participants/{participantId}', requirements: ['listId' => '\d+', 'participantId' => '\d+'])]
	public function deleteParticipant(string $listId, string $participantId): DataResponse {
		$list = $this->findList($listId);
		$this->participantService->delete($this->findParticipant($list, $participantId));
		return new DataResponse(null);
	}

	/**
	 * @throws OCSNotFoundException If the list does not exist or is not owned by the current user
	 */
	private function findList(string $listId): SecretSantaList {
		if ($this->userId === null) {
			throw new OCSNotFoundException();
		}
		try {
			return $this->listService->get($listId, $this->userId);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	/**
	 * @throws OCSNotFoundException If the participant is not part of the list
	 */
	private function findParticipant(SecretSantaList $list, string $participantId): Participant {
		try {
			return $this->participantService->get($list, $participantId);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}
}
