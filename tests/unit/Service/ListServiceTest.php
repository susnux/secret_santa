<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Service;

use OCA\SecretSanta\Db\DrawMapper;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Db\ParticipantMapper;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCA\SecretSanta\Db\ShareMapper;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\ParticipantService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class ListServiceTest extends TestCase {

	private const string USER_ID = 'secret-santa-list-service-test';
	private const string OTHER_USER_ID = 'secret-santa-list-service-test-other';
	private const string MEMBER = 'secret-santa-list-service-test-member';
	private const string GROUP = 'secret-santa-list-service-test-group';
	private const int NOW = 1_800_000_000;

	private ListService $service;
	private ParticipantMapper $participantMapper;
	private ShareMapper $shareMapper;
	private SecretSantaListMapper $listMapper;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));
		$this->participantMapper = Server::get(ParticipantMapper::class);
		$this->shareMapper = Server::get(ShareMapper::class);
		$this->listMapper = Server::get(SecretSantaListMapper::class);
		$timeFactory = $this->createStub(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(self::NOW);
		$this->service = new ListService(
			Server::get(IDBConnection::class),
			$l10n,
			$this->listMapper,
			$this->participantMapper,
			Server::get(ExclusionMapper::class),
			Server::get(DrawMapper::class),
			Server::get(PairingMapper::class),
			$this->shareMapper,
			$timeFactory,
			Server::get(IUserManager::class),
			Server::get(IGroupManager::class),
		);
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ([self::USER_ID, self::OTHER_USER_ID] as $owner) {
			foreach ($this->listMapper->getListsByOwner($owner) as $list) {
				$this->service->delete($list);
			}
		}
		Server::get(IUserManager::class)->get(self::MEMBER)?->delete();
		Server::get(IGroupManager::class)->get(self::GROUP)?->delete();
		parent::tearDown();
	}

	/**
	 * Create the user MEMBER in the group GROUP
	 */
	private function createMember(): IUser {
		$userManager = Server::get(IUserManager::class);
		$userManager->get(self::MEMBER)?->delete();
		$member = $userManager->createUser(self::MEMBER, bin2hex(random_bytes(16)) . 'Aa1!');
		$this->assertInstanceOf(IUser::class, $member);
		$group = Server::get(IGroupManager::class)->createGroup(self::GROUP);
		$this->assertNotNull($group);
		$group->addUser($member);
		return $member;
	}

	/**
	 * @param list<SecretSantaList> $lists
	 * @return list<string>
	 */
	private static function getIds(array $lists): array {
		return array_map(static fn (SecretSantaList $list): string => $list->getId(), $lists);
	}

	public function testCreate(): void {
		$list = $this->service->create(self::USER_ID, '  Christmas  ');

		$this->assertMatchesRegularExpression('/^\d+$/', $list->getId());
		$this->assertSame(self::USER_ID, $list->getOwner());
		$this->assertSame('Christmas', $list->getName());
		$this->assertNull($list->getSenderName());
		$this->assertSame('translated: Your Secret Santa partner', $list->getMailSubject());
		$this->assertStringStartsWith('translated: Hello {name},', $list->getMailBody());
		$this->assertStringContainsString('{partner}', $list->getMailBody());
		$this->assertSame($this->service->serialize($list), $this->service->serialize($this->service->get($list->getId(), self::USER_ID)));
	}

	public function testCreateRejectsEmptyName(): void {
		try {
			$this->service->create(self::USER_ID, '  ');
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('The name of the list must not be empty.', $error->getMessage());
			$this->assertSame('translated: The name of the list must not be empty.', $error->getHint());
		}
		$this->assertSame([], $this->service->getAccessible(self::USER_ID));
	}

	public function testCreateRejectsTooLongName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The name must not be longer than 255 characters.');
		$this->service->create(self::USER_ID, str_repeat('ä', 256));
	}

	public function testGetOnlyReturnsListsOfTheUser(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');

		$this->expectException(DoesNotExistException::class);
		$this->service->get($list->getId(), self::OTHER_USER_ID);
	}

	public function testGetRejectsInvalidId(): void {
		$this->expectException(DoesNotExistException::class);
		$this->service->get('99999999999999999999', self::USER_ID);
	}

	public function testGetListSharedWithAGroupOfTheUser(): void {
		$this->createMember();
		$list = $this->service->create(self::USER_ID, 'Christmas');
		$this->shareMapper->insert($list, self::GROUP);

		$this->assertSame($list->getId(), $this->service->get($list->getId(), self::MEMBER)->getId());
		$this->expectException(DoesNotExistException::class);
		$this->service->get($list->getId(), self::OTHER_USER_ID);
	}

	public function testGetRejectsListsSharedWithOtherGroups(): void {
		$this->createMember();
		$list = $this->service->create(self::USER_ID, 'Christmas');
		$this->shareMapper->insert($list, self::GROUP . '-other');

		$this->expectException(DoesNotExistException::class);
		$this->service->get($list->getId(), self::MEMBER);
	}

	public function testGetAccessible(): void {
		$first = $this->service->create(self::USER_ID, 'First');
		$second = $this->service->create(self::USER_ID, 'Second');
		$this->service->create(self::OTHER_USER_ID, 'Other');

		$this->assertSame([$first->getId(), $second->getId()], self::getIds($this->service->getAccessible(self::USER_ID)));
	}

	public function testGetAccessibleIncludesSharedListsOrderedById(): void {
		$this->createMember();
		$shared = $this->service->create(self::USER_ID, 'Shared');
		$own = $this->service->create(self::MEMBER, 'Own');
		$sharedAndOwn = $this->service->create(self::MEMBER, 'Own and shared');
		$this->service->create(self::USER_ID, 'Not shared');
		$this->shareMapper->insert($shared, self::GROUP);
		$this->shareMapper->insert($sharedAndOwn, self::GROUP);

		try {
			$this->assertSame(
				[$shared->getId(), $own->getId(), $sharedAndOwn->getId()],
				self::getIds($this->service->getAccessible(self::MEMBER)),
			);
		} finally {
			$this->service->delete($own);
			$this->service->delete($sharedAndOwn);
		}
	}

	public function testIsOwner(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');

		$this->assertTrue($this->service->isOwner($list, self::USER_ID));
		$this->assertFalse($this->service->isOwner($list, self::OTHER_USER_ID));
	}

	public function testRemoveOwnerKeepsSharedListsWithoutOwner(): void {
		$shared = $this->service->create(self::USER_ID, 'Shared');
		$notShared = $this->service->create(self::USER_ID, 'Not shared');
		$other = $this->service->create(self::OTHER_USER_ID, 'Other');
		$this->shareMapper->insert($shared, self::GROUP);

		$this->service->removeOwner(self::USER_ID);

		$orphan = $this->listMapper->getList($shared->getId());
		try {
			$this->assertNull($orphan->getOwner());
			$this->assertCount(1, $this->shareMapper->getShares($orphan));
			$this->assertSame([], $this->listMapper->getListsByIds([$notShared->getId()]));
			$this->assertSame(self::OTHER_USER_ID, $this->listMapper->getList($other->getId())->getOwner());
		} finally {
			$this->service->delete($orphan);
		}
	}

	public function testUpdateOnlyChangesGivenValues(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');
		$body = $list->getMailBody();

		$this->service->update($list, senderName: ' Santa ', mailSubject: ' Your partner ');

		$updated = $this->service->get($list->getId(), self::USER_ID);
		$this->assertSame('Christmas', $updated->getName());
		$this->assertSame('Santa', $updated->getSenderName());
		$this->assertSame('Your partner', $updated->getMailSubject());
		$this->assertSame($body, $updated->getMailBody());

		$this->service->update($updated, name: 'Party', senderName: '', mailBody: "Hi {name}\n");
		$updated = $this->service->get($list->getId(), self::USER_ID);
		$this->assertSame('Party', $updated->getName());
		$this->assertNull($updated->getSenderName());
		$this->assertSame("Hi {name}\n", $updated->getMailBody());
	}

	public function testUpdateRejectsEmptySubject(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The subject must not be empty.');
		$this->service->update($list, mailSubject: ' ');
	}

	public function testUpdateRejectsPartnerInSubject(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');

		try {
			$this->service->update($list, mailSubject: 'You give {partner} a gift');
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('The subject must not contain the partner.', $error->getMessage());
			$this->assertSame('translated: The partner can only be mentioned in the text of the email, as mail servers often log the subjects of emails.', $error->getHint());
		}
		$this->assertSame('translated: Your Secret Santa partner', $this->service->get($list->getId(), self::USER_ID)->getMailSubject());
	}

	public function testUpdateRejectsEmptyBody(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The email body must not be empty.');
		$this->service->update($list, mailBody: "\n ");
	}

	public function testUpdateRejectsTooLongBody(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The email body must not be longer than 10000 characters.');
		$this->service->update($list, mailBody: str_repeat('a', 10_001));
	}

	public function testArchiveAndUnarchive(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');
		$this->assertNull($list->getArchivedAt());

		$this->service->archive($list);
		$archived = $this->service->get($list->getId(), self::USER_ID);
		$this->assertSame(self::NOW, $archived->getArchivedAt());

		$this->service->unarchive($archived);
		$this->assertNull($this->service->get($list->getId(), self::USER_ID)->getArchivedAt());
	}

	public function testArchiveKeepsTheTimeOfArchiving(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');
		$list->setArchivedAt(self::NOW - 100);
		$this->service->archive($list);

		$this->service->archive($list);

		$this->assertSame(self::NOW - 100, $this->service->get($list->getId(), self::USER_ID)->getArchivedAt());
	}

	public function testCheckEditable(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');
		$this->service->checkEditable($list);

		$this->service->archive($list);
		try {
			$this->service->checkEditable($list);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('The list is archived.', $error->getMessage());
			$this->assertSame('translated: The list is archived. Unarchive it to change it.', $error->getHint());
		}
	}

	public function testDeleteRemovesParticipants(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');
		$other = $this->service->create(self::USER_ID, 'Other');
		$participantService = Server::get(ParticipantService::class);
		$participantService->addGuest($list, 'Alice', 'alice@example.com');
		$participantService->addGuest($other, 'Bob', 'bob@example.com');

		$this->service->delete($list);

		$this->assertSame([$other->getId()], self::getIds($this->service->getAccessible(self::USER_ID)));
		$this->assertSame([], $this->participantMapper->getParticipants($list));
		$this->assertCount(1, $this->participantMapper->getParticipants($other));
	}

	public function testDeleteRemovesShares(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');
		$other = $this->service->create(self::USER_ID, 'Other');
		$this->shareMapper->insert($list, self::GROUP);
		$this->shareMapper->insert($other, self::GROUP);

		$this->service->delete($list);

		$this->assertSame([], $this->shareMapper->getShares($list));
		$this->assertCount(1, $this->shareMapper->getShares($other));
	}

	public function testSerialize(): void {
		$list = $this->service->create(self::USER_ID, 'Christmas');

		$this->assertSame([
			'id' => $list->getId(),
			'owner' => self::USER_ID,
			'ownerDisplayName' => self::USER_ID,
			'name' => 'Christmas',
			'senderName' => null,
			'mailSubject' => $list->getMailSubject(),
			'mailBody' => $list->getMailBody(),
			'exclusionsImported' => false,
			'archivedAt' => null,
		], $this->service->serialize($list));

		$this->service->archive($list);
		$this->assertSame(self::NOW, $this->service->serialize($list)['archivedAt']);
	}

	public function testSerializeOwner(): void {
		$member = $this->createMember();
		$member->setDisplayName('Mia Member');
		$list = $this->service->create(self::MEMBER, 'Christmas');

		try {
			$serialized = $this->service->serialize($list);
			$this->assertSame(self::MEMBER, $serialized['owner']);
			$this->assertSame('Mia Member', $serialized['ownerDisplayName']);

			$list->setOwner(null);
			$serialized = $this->service->serialize($list);
			$this->assertNull($serialized['owner']);
			$this->assertNull($serialized['ownerDisplayName']);
		} finally {
			$this->service->delete($list);
		}
	}
}
