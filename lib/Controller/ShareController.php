<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Controller;

use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\ResponseDefinitions;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCA\SecretSanta\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;

/**
 * @psalm-import-type SecretSantaShare from ResponseDefinitions
 *
 * @psalm-api Loaded by the router
 */
final class ShareController extends BaseOCSController {

	public function __construct(
		IRequest $request,
		ListService $listService,
		ParticipantService $participantService,
		?string $userId,
		private ShareService $shareService,
	) {
		parent::__construct($request, $listService, $participantService, $userId);
	}

	/**
	 * Get the groups a list is shared with
	 *
	 * @param string $listId The id of the list
	 * @return DataResponse<Http::STATUS_OK, list<SecretSantaShare>, array{}>
	 * @throws OCSNotFoundException List not found or not accessible by the current user
	 *
	 * 200: Shares returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/lists/{listId}/shares', requirements: ['listId' => '\d+'])]
	public function getShares(string $listId): DataResponse {
		$shares = $this->shareService->getShares($this->findList($listId));
		return new DataResponse(array_map($this->shareService->serialize(...), $shares));
	}

	/**
	 * Share a list with a group
	 *
	 * All members of the group can change the list, but only the owner can delete and share it.
	 * The sharing restrictions of the server apply, e.g. sharing only with the own groups.
	 *
	 * @param string $listId The id of the list
	 * @param string $groupId The id of the group
	 * @return DataResponse<Http::STATUS_CREATED, SecretSantaShare, array{}>
	 * @throws OCSBadRequestException Unknown group, sharing with the group is not allowed or the list is already shared with it
	 * @throws OCSForbiddenException The current user is not the owner of the list
	 * @throws OCSNotFoundException List not found or not accessible by the current user
	 *
	 * 201: List shared
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/lists/{listId}/shares', requirements: ['listId' => '\d+'])]
	public function createShare(string $listId, string $groupId): DataResponse {
		$list = $this->findOwnedList($listId);
		try {
			$share = $this->shareService->share($list, $groupId, (string)$this->userId);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		}
		return new DataResponse($this->shareService->serialize($share), Http::STATUS_CREATED);
	}

	/**
	 * Stop sharing a list with a group
	 *
	 * @param string $listId The id of the list
	 * @param string $groupId The id of the group
	 * @return DataResponse<Http::STATUS_OK, null, array{}>
	 * @throws OCSForbiddenException The current user is not the owner of the list
	 * @throws OCSNotFoundException List not found or not accessible by the current user
	 *
	 * 200: Share removed, or the list was not shared with the group
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/lists/{listId}/shares/{groupId}', requirements: ['listId' => '\d+', 'groupId' => '.+'])]
	public function deleteShare(string $listId, string $groupId): DataResponse {
		$this->shareService->unshare($this->findOwnedList($listId), $groupId);
		return new DataResponse(null);
	}
}
