<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Controller;

use OCA\SecretSanta\AppInfo\Application;
use OCA\SecretSanta\Db\Participant;
use OCA\SecretSanta\Db\SecretSantaList as SecretSantaListEntity;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Base of the API controllers, loads the lists the current user can access and their participants
 */
abstract class BaseOCSController extends OCSController {

	public function __construct(
		IRequest $request,
		protected ListService $listService,
		protected ParticipantService $participantService,
		protected ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @throws OCSNotFoundException If the list does not exist or the current user can not access it
	 */
	protected function findList(string $listId): SecretSantaListEntity {
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
	 * Find a list of the current user to change it
	 *
	 * @throws OCSNotFoundException If the list does not exist or the current user can not access it
	 * @throws OCSBadRequestException If the list is archived
	 */
	protected function findEditableList(string $listId): SecretSantaListEntity {
		$list = $this->findList($listId);
		try {
			$this->listService->checkEditable($list);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		}
		return $list;
	}

	/**
	 * Find a list owned by the current user, to delete or share it
	 *
	 * @throws OCSNotFoundException If the list does not exist or the current user can not access it
	 * @throws OCSForbiddenException If the current user can access the list but does not own it
	 */
	protected function findOwnedList(string $listId): SecretSantaListEntity {
		$list = $this->findList($listId);
		if ($this->userId === null || !$this->listService->isOwner($list, $this->userId)) {
			throw new OCSForbiddenException($this->listService->getOwnerOnlyHint());
		}
		return $list;
	}

	/**
	 * @throws OCSNotFoundException If the participant is not part of the list
	 */
	protected function findParticipant(SecretSantaListEntity $list, string $participantId): Participant {
		try {
			return $this->participantService->get($list, $participantId);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}
}
