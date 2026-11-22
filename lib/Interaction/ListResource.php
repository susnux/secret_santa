<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Interaction;

use OCA\SecretSanta\AppInfo\Application;
use OCP\Interaction\InteractionResource;

/**
 * A list as resource of interactions like sharing, to let the server and other apps restrict them
 */
final readonly class ListResource implements InteractionResource {

	public function __construct(
		public string $listId,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID . '/list/' . $this->listId;
	}
}
