<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Exception;

use OCP\HintException;

/**
 * Input provided by the user is invalid.
 *
 * The message is English for logging, the hint is translated and can be shown to the user.
 */
final class InvalidInputException extends HintException {
}
