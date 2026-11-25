<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit;

use OC\Log\ExceptionSerializer;
use OC\SystemConfig;
use OCP\Server;

/**
 * Check what the Nextcloud log contains about an exception, including the arguments in its stack trace
 */
trait LoggedExceptionTrait {

	/**
	 * Run the callback, which must throw, with the arguments of function calls recorded in stack traces.
	 *
	 * @param \Closure(): mixed $callback
	 * @return string The exception as JSON, as it is written to the log
	 */
	private function logThrownException(\Closure $callback): string {
		$ignoreArgs = ini_set('zend.exception_ignore_args', '0');
		try {
			$callback();
		} catch (\Throwable $error) {
			$serialized = (new ExceptionSerializer(Server::get(SystemConfig::class)))->serializeException($error);
			return json_encode($serialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		} finally {
			if ($ignoreArgs !== false) {
				ini_set('zend.exception_ignore_args', $ignoreArgs);
			}
		}
		$this->fail('Expected an exception');
	}
}
