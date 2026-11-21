<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Service;

use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCA\SecretSanta\Service\Recipient;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IEmailValidator;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParticipantServiceTest extends TestCase {

	private const string OWNER = 'secret-santa-participant-test-owner';
	private const string USER = 'secret-santa-participant-test-user';
	private const string GROUP = 'secret-santa-participant-test-group';
	private const string EXCLUDED_GROUP = 'secret-santa-participant-test-excluded';

	private ListService $listService;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private IUser $owner;
	private IUser $user;
	private SecretSantaList $list;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->listService = Server::get(ListService::class);
		$this->userManager = Server::get(IUserManager::class);
		$this->groupManager = Server::get(IGroupManager::class);
		$this->owner = $this->createUser(self::OWNER);
		$this->user = $this->createUser(self::USER);
		$this->user->setDisplayName('Bob Builder');
		$this->list = $this->listService->create(self::OWNER, 'Christmas');
	}

	#[\Override]
	protected function tearDown(): void {
		$this->listService->deleteByOwner(self::OWNER);
		$this->owner->delete();
		$this->user->delete();
		$this->groupManager->get(self::GROUP)?->delete();
		$this->groupManager->get(self::EXCLUDED_GROUP)?->delete();
		parent::tearDown();
	}

	private function createUser(string $userId): IUser {
		$this->userManager->get($userId)?->delete();
		$user = $this->userManager->createUser($userId, bin2hex(random_bytes(16)) . 'Aa1!');
		$this->assertInstanceOf(IUser::class, $user);
		return $user;
	}

	/**
	 * @param bool $groupMembersOnly If sharing is restricted to group members
	 * @param list<string> $excludedGroups Groups excluded from the restriction
	 */
	private function createService(bool $groupMembersOnly = false, array $excludedGroups = []): ParticipantService {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));
		$shareManager = $this->createStub(IShareManager::class);
		$shareManager->method('shareWithGroupMembersOnly')->willReturn($groupMembersOnly);
		$shareManager->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn($excludedGroups);
		return new ParticipantService(
			Server::get(IDBConnection::class),
			$l10n,
			$this->userManager,
			$this->groupManager,
			$shareManager,
			Server::get(IEmailValidator::class),
			Server::get(ParticipantMapper::class),
			Server::get(ExclusionMapper::class),
			Server::get(PairingMapper::class),
		);
	}

	private function addToGroup(IUser $user, string $groupId): void {
		$group = $this->groupManager->get($groupId) ?? $this->groupManager->createGroup($groupId);
		$this->assertNotNull($group);
		$group->addUser($user);
	}

	/**
	 * @return list<string>
	 */
	private function getParticipantIds(ParticipantService $service, SecretSantaList $list): array {
		return array_map(static fn ($participant) => $participant->getId(), $service->getParticipants($list));
	}

	public function testAddUser(): void {
		$service = $this->createService();

		$participant = $service->addUser($this->list, self::USER);

		$this->assertFalse($participant->isGuest());
		$this->assertSame([
			'id' => $participant->getId(),
			'userId' => self::USER,
			'displayName' => 'Bob Builder',
			'email' => null,
			'hasEmail' => false,
		], $service->serialize($participant));

		$this->user->setSystemEMailAddress('bob@example.com');
		$this->assertTrue($service->serialize($participant)['hasEmail']);
		$this->assertNull($service->serialize($participant)['email']);
		$this->assertSame([$participant->getId()], $this->getParticipantIds($service, $this->list));
	}

	public function testAddUserRejectsUnknownUser(): void {
		try {
			$this->createService()->addUser($this->list, 'secret-santa-participant-test-unknown');
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('Unknown user.', $error->getMessage());
			$this->assertSame('translated: The user does not exist.', $error->getHint());
		}
	}

	public function testAddUserRejectsDisabledUser(): void {
		$this->user->setEnabled(false);

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Unknown user.');
		$this->createService()->addUser($this->list, self::USER);
	}

	public function testAddUserRejectsDuplicate(): void {
		$service = $this->createService();
		$service->addUser($this->list, self::USER);

		try {
			$service->addUser($this->list, self::USER);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('The user already participates.', $error->getMessage());
			$this->assertSame('translated: Bob Builder is already a participant.', $error->getHint());
		}
		$this->assertCount(1, $service->getParticipants($this->list));
	}

	public static function dataAddUserWithGroupRestriction(): array {
		return [
			'no common group' => [[], [], false],
			'common group' => [[self::GROUP], [], true],
			'only common group is excluded' => [[self::EXCLUDED_GROUP], [self::EXCLUDED_GROUP], false],
			'common group besides the excluded one' => [[self::GROUP, self::EXCLUDED_GROUP], [self::EXCLUDED_GROUP], true],
		];
	}

	/**
	 * @param list<string> $commonGroups Groups of both the owner and the user
	 * @param list<string> $excludedGroups Groups excluded from the restriction
	 */
	#[DataProvider('dataAddUserWithGroupRestriction')]
	public function testAddUserWithGroupRestriction(array $commonGroups, array $excludedGroups, bool $allowed): void {
		foreach ($commonGroups as $groupId) {
			$this->addToGroup($this->owner, $groupId);
			$this->addToGroup($this->user, $groupId);
		}
		$service = $this->createService(true, $excludedGroups);

		if (!$allowed) {
			$this->expectException(InvalidInputException::class);
			$this->expectExceptionMessage('Unknown user.');
		}
		$this->assertSame(self::USER, $service->addUser($this->list, self::USER)->getUserId());
	}

	public function testOwnerCanAlwaysAddThemselves(): void {
		$this->assertSame(self::OWNER, $this->createService(true)->addUser($this->list, self::OWNER)->getUserId());
	}

	public function testAddGuest(): void {
		$service = $this->createService();

		$participant = $service->addGuest($this->list, ' Alice ', ' alice@example.com ');

		$this->assertTrue($participant->isGuest());
		$this->assertSame([
			'id' => $participant->getId(),
			'userId' => null,
			'displayName' => 'Alice',
			'email' => 'alice@example.com',
			'hasEmail' => true,
		], $service->serialize($participant));
		$this->assertSame($service->serialize($participant), $service->serialize($service->get($this->list, $participant->getId())));
	}

	public static function dataInvalidGuest(): array {
		return [
			'empty name' => [' ', 'alice@example.com', 'The name must not be empty.', 'translated: The name of the participant must not be empty.'],
			'invalid email' => ['Alice', 'alice', 'Invalid email address.', 'translated: The email address is not valid.'],
			'empty email' => ['Alice', '', 'Invalid email address.', 'translated: The email address is not valid.'],
			'email of another guest' => ['Alice', 'BOB@example.com', 'The email address is already used.', 'translated: Another participant already uses this email address.'],
		];
	}

	#[DataProvider('dataInvalidGuest')]
	public function testAddGuestRejectsInvalidValues(string $displayName, string $email, string $message, string $hint): void {
		$service = $this->createService();
		$service->addGuest($this->list, 'Bob', 'bob@example.com');

		try {
			$service->addGuest($this->list, $displayName, $email);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame($message, $error->getMessage());
			$this->assertSame($hint, $error->getHint());
		}
		$this->assertCount(1, $service->getParticipants($this->list));
	}

	public function testAddGuestRejectsTooLongName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The name and email address must not be longer than 255 characters.');
		$this->createService()->addGuest($this->list, str_repeat('a', 256), 'alice@example.com');
	}

	public function testParticipantLimit(): void {
		$service = $this->createService();
		for ($i = 0; $i < ParticipantService::MAX_PARTICIPANTS; $i++) {
			$service->addGuest($this->list, 'Guest ' . $i, 'guest' . $i . '@example.com');
		}

		try {
			$service->addUser($this->list, self::USER);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('The list has too many participants.', $error->getMessage());
			$this->assertSame('translated: A list can not have more than 100 participants.', $error->getHint());
		}
		$this->expectException(InvalidInputException::class);
		$service->addGuest($this->list, 'Another guest', 'another@example.com');
	}

	public function testUpdateGuest(): void {
		$service = $this->createService();
		$participant = $service->addGuest($this->list, 'Alice', 'alice@example.com');
		$service->addGuest($this->list, 'Bob', 'bob@example.com');

		$service->updateGuest($this->list, $participant, 'Alice Liddell', 'ALICE@example.com');

		$updated = $service->get($this->list, $participant->getId());
		$this->assertSame('Alice Liddell', $updated->getDisplayName());
		$this->assertSame('ALICE@example.com', $updated->getEmail());

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The email address is already used.');
		$service->updateGuest($this->list, $updated, 'Alice', 'bob@example.com');
	}

	public function testUpdateGuestRejectsUsers(): void {
		$service = $this->createService();
		$participant = $service->addUser($this->list, self::USER);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Only guests can be changed.');
		$service->updateGuest($this->list, $participant, 'Bob', 'bob@example.com');
	}

	public function testGetRecipient(): void {
		$service = $this->createService();
		$guest = $service->addGuest($this->list, 'Alice', 'alice@example.com');
		$user = $service->addUser($this->list, self::USER);
		$this->user->setSystemEMailAddress('bob@example.com');

		$this->assertEquals(new Recipient('Alice', 'alice@example.com'), $service->getRecipient($guest));
		$this->assertEquals(new Recipient('Bob Builder', 'bob@example.com'), $service->getRecipient($user));
	}

	public function testGetRecipientOfUserWithoutEmail(): void {
		$service = $this->createService();
		$user = $service->addUser($this->list, self::USER);

		try {
			$service->getRecipient($user);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('Participant without email address.', $error->getMessage());
			$this->assertSame('translated: Bob Builder has no email address and can not be notified.', $error->getHint());
		}
	}

	public function testGetRejectsParticipantOfAnotherList(): void {
		$service = $this->createService();
		$other = $this->listService->create(self::OWNER, 'Other');
		$participant = $service->addGuest($other, 'Alice', 'alice@example.com');

		$this->expectException(DoesNotExistException::class);
		$service->get($this->list, $participant->getId());
	}

	public function testDelete(): void {
		$service = $this->createService();
		$alice = $service->addGuest($this->list, 'Alice', 'alice@example.com');
		$bob = $service->addGuest($this->list, 'Bob', 'bob@example.com');

		$service->delete($alice);

		$this->assertSame([$bob->getId()], $this->getParticipantIds($service, $this->list));
	}

	public function testDeleteByUser(): void {
		$service = $this->createService();
		$other = $this->listService->create(self::OWNER, 'Other');
		$service->addUser($this->list, self::USER);
		$service->addUser($other, self::USER);
		$guest = $service->addGuest($other, 'Alice', 'alice@example.com');

		$service->deleteByUser(self::USER);

		$this->assertSame([], $service->getParticipants($this->list));
		$this->assertSame([$guest->getId()], $this->getParticipantIds($service, $other));
	}

	public function testMatchParticipants(): void {
		$service = $this->createService();
		$this->owner->setSystemEMailAddress('owner@example.com');
		$lastYear = $this->listService->create(self::OWNER, 'Last year');
		$user = $service->addUser($lastYear, self::USER);
		$owner = $service->addUser($lastYear, self::OWNER);
		$carol = $service->addGuest($lastYear, 'Carol', 'carol@example.com');
		$dave = $service->addGuest($lastYear, 'Dave', 'dave@example.com');

		$matchingUser = $service->addUser($this->list, self::USER);
		$ownerAsGuest = $service->addGuest($this->list, 'The owner', 'Owner@Example.com');
		$matchingCarol = $service->addGuest($this->list, 'Caroline', 'CAROL@example.com');
		$service->addGuest($this->list, 'Erin', 'erin@example.com');

		$matches = $service->matchParticipants($service->getParticipants($lastYear), $service->getParticipants($this->list));

		$this->assertSame($matchingUser->getId(), $matches[$user->getId()]);
		$this->assertSame($ownerAsGuest->getId(), $matches[$owner->getId()]);
		$this->assertSame($matchingCarol->getId(), $matches[$carol->getId()]);
		$this->assertArrayNotHasKey($dave->getId(), $matches);
		$this->assertCount(3, $matches);
	}

	public function testAmbiguousParticipantsAreNotMatched(): void {
		$service = $this->createService();
		$this->user->setSystemEMailAddress('bob@example.com');
		$lastYear = $this->listService->create(self::OWNER, 'Last year');
		// The user and a guest with the email address of the user are the same person in the other list
		$service->addUser($lastYear, self::USER);
		$service->addGuest($lastYear, 'Bob', 'bob@example.com');
		$carol = $service->addGuest($lastYear, 'Carol', 'carol@example.com');

		$service->addUser($this->list, self::USER);
		// Two participants with the email address of carol
		$service->addUser($this->list, self::OWNER);
		$this->owner->setSystemEMailAddress('carol@example.com');
		$service->addGuest($this->list, 'Carol', 'carol@example.com');

		$this->assertSame([], $service->matchParticipants($service->getParticipants($lastYear), $service->getParticipants($this->list)));
		$this->assertArrayNotHasKey($carol->getId(), $service->matchParticipants([$carol], $service->getParticipants($this->list)));
	}
}
