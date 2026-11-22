<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Listener;

use OCA\SecretSanta\Service\ShareService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;

/**
 * Remove the shares with deleted groups
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
final class GroupDeletedListener implements IEventListener {

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private ShareService $shareService,
	) {
	}

	/**
	 * @param GroupDeletedEvent $event
	 */
	#[\Override]
	public function handle(Event $event): void {
		$this->shareService->removeGroup($event->getGroup()->getGID());
	}
}
