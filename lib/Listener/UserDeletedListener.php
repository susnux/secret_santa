<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Listener;

use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Delete the lists of deleted users and remove them from the lists of other users
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
final class UserDeletedListener implements IEventListener {

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private ListService $listService,
		private ParticipantService $participantService,
	) {
	}

	/**
	 * @param UserDeletedEvent $event
	 */
	#[\Override]
	public function handle(Event $event): void {
		$userId = $event->getUser()->getUID();
		$this->listService->deleteByOwner($userId);
		$this->participantService->deleteByUser($userId);
	}
}
