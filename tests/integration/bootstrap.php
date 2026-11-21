<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Integration tests only talk to the server over HTTP, so Nextcloud itself is not loaded.
require_once __DIR__ . '/IntegrationTestCase.php';
