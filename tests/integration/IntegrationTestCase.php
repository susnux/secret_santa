<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class for tests that send requests to a running Nextcloud instance with the app enabled.
 *
 * The instance is configured with the `NEXTCLOUD_URL`, `NEXTCLOUD_ADMIN_USER` and `NEXTCLOUD_ADMIN_PASSWORD`
 * environment variables. Users created with `createUser()` are deleted after each test, which also deletes their lists
 * unless they are shared. Groups created with `createGroup()` are deleted after each test, after the users.
 */
abstract class IntegrationTestCase extends TestCase {

	private Client $client;
	/** @var array<string, string> user id => password */
	private array $users = [];
	/** @var list<string> */
	private array $groups = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->client = new Client([
			'base_uri' => rtrim((string)getenv('NEXTCLOUD_URL'), '/') . '/',
			RequestOptions::HTTP_ERRORS => false,
			RequestOptions::HEADERS => [
				'Accept' => 'application/json',
				'OCS-APIRequest' => 'true',
			],
		]);
	}

	#[\Override]
	protected function tearDown(): void {
		foreach (array_keys($this->users) as $userId) {
			$response = $this->adminRequest('DELETE', 'ocs/v2.php/cloud/users/' . rawurlencode($userId));
			$this->assertSame(200, $response->getStatusCode(), 'Failed to delete test user ' . $userId);
		}
		$this->users = [];
		foreach ($this->groups as $groupId) {
			$response = $this->adminRequest('DELETE', 'ocs/v2.php/cloud/groups/' . rawurlencode($groupId));
			$this->assertSame(200, $response->getStatusCode(), 'Failed to delete test group ' . $groupId);
		}
		$this->groups = [];
		parent::tearDown();
	}

	/**
	 * Create a user with a random id and password.
	 *
	 * @return string The user id
	 */
	protected function createUser(): string {
		$userId = 'secret-santa-test-' . bin2hex(random_bytes(6));
		$password = bin2hex(random_bytes(16)) . 'Aa1!';
		$response = $this->adminRequest('POST', 'ocs/v2.php/cloud/users', [
			RequestOptions::JSON => ['userid' => $userId, 'password' => $password],
		]);
		$this->assertSame(200, $response->getStatusCode(), 'Failed to create test user: ' . $response->getBody());

		$this->users[$userId] = $password;
		return $userId;
	}

	/**
	 * Create a group with a random id and the given members.
	 *
	 * @param list<string> $members The ids of the users to add to the group
	 * @return string The group id
	 */
	protected function createGroup(array $members = []): string {
		$groupId = 'secret-santa-test-' . bin2hex(random_bytes(6));
		$response = $this->adminRequest('POST', 'ocs/v2.php/cloud/groups', [
			RequestOptions::JSON => ['groupid' => $groupId],
		]);
		$this->assertSame(200, $response->getStatusCode(), 'Failed to create test group: ' . $response->getBody());
		$this->groups[] = $groupId;

		foreach ($members as $userId) {
			$response = $this->adminRequest('POST', 'ocs/v2.php/cloud/users/' . rawurlencode($userId) . '/groups', [
				RequestOptions::JSON => ['groupid' => $groupId],
			]);
			$this->assertSame(200, $response->getStatusCode(), 'Failed to add the user to the group: ' . $response->getBody());
		}
		return $groupId;
	}

	/**
	 * Delete a group created by `createGroup()` before the end of the test.
	 */
	protected function deleteGroup(string $groupId): void {
		$response = $this->adminRequest('DELETE', 'ocs/v2.php/cloud/groups/' . rawurlencode($groupId));
		$this->assertSame(200, $response->getStatusCode(), 'Failed to delete test group ' . $groupId);
		$this->groups = array_values(array_diff($this->groups, [$groupId]));
	}

	/**
	 * Send a request to the OCS API of the app as the given user.
	 *
	 * @param string $path Path relative to the app's OCS root, e.g. `api/v1/lists`
	 * @param array<string, mixed> $options Guzzle request options
	 */
	protected function ocsRequest(string $userId, string $method, string $path, array $options = []): ResponseInterface {
		$this->assertArrayHasKey($userId, $this->users, 'Requests can only be sent as users created by the test');
		return $this->client->request($method, 'ocs/v2.php/apps/secret_santa/' . ltrim($path, '/'), [
			...$options,
			RequestOptions::AUTH => [$userId, $this->users[$userId]],
		]);
	}

	/**
	 * Request a page of the app as the given user without following redirects.
	 *
	 * @param string $path Path relative to the app's web root, e.g. `42`
	 */
	protected function pageRequest(string $userId, string $path): ResponseInterface {
		$this->assertArrayHasKey($userId, $this->users, 'Requests can only be sent as users created by the test');
		return $this->client->request('GET', 'index.php/apps/secret_santa/' . ltrim($path, '/'), [
			RequestOptions::ALLOW_REDIRECTS => false,
			RequestOptions::AUTH => [$userId, $this->users[$userId]],
			RequestOptions::HEADERS => ['Accept' => 'text/html'],
		]);
	}

	/**
	 * Delete a user created by `createUser()` before the end of the test.
	 */
	protected function deleteUser(string $userId): void {
		$response = $this->adminRequest('DELETE', 'ocs/v2.php/cloud/users/' . rawurlencode($userId));
		$this->assertSame(200, $response->getStatusCode(), 'Failed to delete test user ' . $userId);
		unset($this->users[$userId]);
	}

	/**
	 * Set the email address of a user created by `createUser()`.
	 */
	protected function setEmail(string $userId, string $email): void {
		$response = $this->adminRequest('PUT', 'ocs/v2.php/cloud/users/' . rawurlencode($userId), [
			RequestOptions::JSON => ['key' => 'email', 'value' => $email],
		]);
		$this->assertSame(200, $response->getStatusCode(), 'Failed to set the email address: ' . $response->getBody());
	}

	/**
	 * Get the raw emails sent to an address from MailHog, the oldest first, the test is skipped without MailHog.
	 *
	 * MailHog is configured with the `MAILHOG_URL` environment variable (e.g. `http://localhost:8025`),
	 * the instance needs to send its emails to the SMTP server of MailHog.
	 *
	 * @return list<string>
	 */
	protected function getMails(string $email): array {
		$url = (string)getenv('MAILHOG_URL');
		if ($url === '') {
			$this->markTestSkipped('MailHog is not configured');
		}

		$response = $this->client->request('GET', rtrim($url, '/') . '/api/v2/search', [
			RequestOptions::QUERY => ['kind' => 'to', 'query' => $email, 'limit' => 50],
			RequestOptions::HEADERS => ['OCS-APIRequest' => null],
		]);
		$this->assertSame(200, $response->getStatusCode(), 'Failed to search the emails in MailHog');
		$result = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
		$this->assertIsArray($result);

		$mails = [];
		foreach (array_reverse($result['items']) as $item) {
			$mails[] = $item['Raw']['Data'];
		}
		return $mails;
	}

	/**
	 * Assert the status code of an OCS response and return its data.
	 */
	protected function assertOcsResponse(int $expectedStatus, ResponseInterface $response): mixed {
		$body = (string)$response->getBody();
		$this->assertSame($expectedStatus, $response->getStatusCode(), 'Unexpected status code, response: ' . $body);

		$json = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		$this->assertIsArray($json);
		$this->assertArrayHasKey('ocs', $json);
		return $json['ocs']['data'];
	}

	/**
	 * @param array<string, mixed> $options Guzzle request options
	 */
	private function adminRequest(string $method, string $path, array $options = []): ResponseInterface {
		return $this->client->request($method, $path, [
			...$options,
			RequestOptions::AUTH => [(string)getenv('NEXTCLOUD_ADMIN_USER'), (string)getenv('NEXTCLOUD_ADMIN_PASSWORD')],
		]);
	}
}
