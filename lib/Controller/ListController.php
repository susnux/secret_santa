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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * @psalm-import-type SecretSantaList from ResponseDefinitions
 *
 * @psalm-api Loaded by the router
 */
final class ListController extends BaseOCSController {

	/**
	 * Get the lists the current user owns or that are shared with their groups
	 *
	 * @return DataResponse<Http::STATUS_OK, list<SecretSantaList>, array{}>
	 *
	 * 200: Lists returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/lists')]
	public function getLists(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([]);
		}
		return new DataResponse(array_map(
			$this->listService->serialize(...),
			$this->listService->getAccessible($this->userId),
		));
	}

	/**
	 * Create a new list
	 *
	 * The list is created with a default email in the language of the current user.
	 *
	 * @param string $name The name of the list (up to 255 characters)
	 * @return DataResponse<Http::STATUS_CREATED, SecretSantaList, array{}>
	 * @throws OCSBadRequestException Invalid name
	 *
	 * 201: List created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/lists')]
	public function createList(string $name): DataResponse {
		if ($this->userId === null) {
			throw new OCSBadRequestException();
		}
		try {
			$list = $this->listService->create($this->userId, $name);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
		return new DataResponse($this->listService->serialize($list), Http::STATUS_CREATED);
	}

	/**
	 * Get a list the current user can access
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, SecretSantaList, array{}>
	 * @throws OCSNotFoundException List not found or not accessible by the current user
	 *
	 * 200: List returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/lists/{listId}', requirements: ['listId' => '\d+'])]
	public function getList(string $listId): DataResponse {
		return new DataResponse($this->listService->serialize($this->findList($listId)));
	}

	/**
	 * Change a list
	 *
	 * Values that are omitted or null are left unchanged.
	 *
	 * @param string $listId The id of the list
	 * @param ?string $name The name of the list (up to 255 characters)
	 * @param ?string $senderName The display name of the sender of the emails (up to 255 characters), an empty string to use the display name of the owner
	 * @param ?string $mailSubject The subject of the email (up to 255 characters), `{name}` is replaced by the name of the participant, `{partner}` is not allowed
	 * @param ?string $mailBody The text of the email (up to 10000 characters), `{name}` and `{partner}` are replaced by the names of the participant and their partner
	 * @return DataResponse<Http::STATUS_OK, SecretSantaList, array{}>
	 * @throws OCSBadRequestException Invalid values or the list is archived
	 * @throws OCSNotFoundException List not found or not accessible by the current user
	 *
	 * 200: List changed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/lists/{listId}', requirements: ['listId' => '\d+'])]
	public function updateList(
		string $listId,
		?string $name = null,
		?string $senderName = null,
		?string $mailSubject = null,
		?string $mailBody = null,
	): DataResponse {
		$list = $this->findEditableList($listId);
		try {
			$list = $this->listService->update($list, $name, $senderName, $mailSubject, $mailBody);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
		return new DataResponse($this->listService->serialize($list));
	}

	/**
	 * Archive a list
	 *
	 * Archived lists can not be changed until they are unarchived, but they can be deleted
	 * and their draws can still be imported as exclusions.
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, SecretSantaList, array{}>
	 * @throws OCSNotFoundException List not found or not accessible by the current user
	 *
	 * 200: List archived
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/lists/{listId}/archive', requirements: ['listId' => '\d+'])]
	public function archiveList(string $listId): DataResponse {
		$list = $this->listService->archive($this->findList($listId));
		return new DataResponse($this->listService->serialize($list));
	}

	/**
	 * Unarchive a list
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, SecretSantaList, array{}>
	 * @throws OCSNotFoundException List not found or not accessible by the current user
	 *
	 * 200: List unarchived
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/lists/{listId}/archive', requirements: ['listId' => '\d+'])]
	public function unarchiveList(string $listId): DataResponse {
		$list = $this->listService->unarchive($this->findList($listId));
		return new DataResponse($this->listService->serialize($list));
	}

	/**
	 * Delete a list including its participants
	 *
	 * Only the owner of the list can delete it.
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, null, array{}>
	 * @throws OCSForbiddenException The current user is not the owner of the list
	 * @throws OCSNotFoundException List not found or not accessible by the current user
	 *
	 * 200: List deleted
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/lists/{listId}', requirements: ['listId' => '\d+'])]
	public function deleteList(string $listId): DataResponse {
		$this->listService->delete($this->findOwnedList($listId));
		return new DataResponse(null);
	}
}
