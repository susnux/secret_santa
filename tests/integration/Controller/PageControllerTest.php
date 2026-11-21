<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Integration\Controller;

use OCA\SecretSanta\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PageControllerTest extends IntegrationTestCase {

	public static function dataPages(): array {
		return [
			'start page' => [''],
			'list' => ['154562972200341504'],
			'not found' => ['not-found'],
		];
	}

	#[DataProvider('dataPages')]
	public function testPagesLoadTheApp(string $path): void {
		$alice = $this->createUser();

		$response = $this->pageRequest($alice, $path);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertStringContainsString('secret_santa-main', (string)$response->getBody());
	}

	public function testOtherPathsAreNotFound(): void {
		$alice = $this->createUser();

		$this->assertSame(404, $this->pageRequest($alice, 'abc')->getStatusCode());
	}
}
