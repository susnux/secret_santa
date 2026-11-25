<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Service;

use OC\Mail\Message;
use OCA\SecretSanta\Db\DrawMapper;
use OCA\SecretSanta\Db\ExclusionMapper;
use OCA\SecretSanta\Db\Pairing;
use OCA\SecretSanta\Db\PairingMapper;
use OCA\SecretSanta\Db\Participant;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\Service\DrawManager;
use OCA\SecretSanta\Service\DrawService;
use OCA\SecretSanta\Service\ExclusionService;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\NotificationService;
use OCA\SecretSanta\Service\ParticipantService;
use OCA\SecretSanta\Tests\Unit\LoggedExceptionTrait;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class DrawManagerTest extends TestCase {
	use LoggedExceptionTrait;

	private const string OWNER = 'secret-santa-draw-manager-test';
	private const string USER = 'secret-santa-draw-manager-test-user';
	private const int TIME = 1_766_000_000;

	private ListService $listService;
	private ParticipantService $participantService;
	private SecretSantaList $list;
	/** @var array<string, Participant> name => participant */
	private array $participants = [];
	/** @var list<Message> */
	private array $messages = [];
	/** @var list<string> Email addresses the emails can not be delivered to */
	private array $failingAddresses = [];
	private LoggerInterface $logger;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->listService = Server::get(ListService::class);
		$this->participantService = Server::get(ParticipantService::class);
		$this->list = $this->listService->create(self::OWNER, 'Christmas');
		// The text contains the partner in a fixed format, to find out the drawn partners
		$this->listService->update($this->list, mailBody: 'PARTNER: {partner}');
		$this->logger = $this->createStub(LoggerInterface::class);
	}

	#[\Override]
	protected function tearDown(): void {
		$this->listService->removeOwner(self::OWNER);
		Server::get(IUserManager::class)->get(self::USER)?->delete();
		parent::tearDown();
	}

	private function createManager(int $seed = 1): DrawManager {
		$mailer = Server::get(IMailer::class);
		$stub = $this->createStub(IMailer::class);
		$stub->method('createEMailTemplate')->willReturnCallback(static fn (string $id, array $data = []) => $mailer->createEMailTemplate($id, $data));
		$stub->method('createMessage')->willReturnCallback(static fn () => $mailer->createMessage());
		$stub->method('send')->willReturnCallback(function (Message $message): array {
			$this->messages[] = $message;
			return array_values(array_intersect(array_keys($message->getTo()), $this->failingAddresses));
		});
		$timeFactory = $this->createStub(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(self::TIME);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));

		return new DrawManager(
			Server::get(IDBConnection::class),
			$timeFactory,
			$this->logger,
			new DrawService($l10n, new Randomizer(new Mt19937($seed))),
			new NotificationService($l10n, $stub, Server::get(IUserManager::class)),
			$this->participantService,
			Server::get(ExclusionMapper::class),
			Server::get(DrawMapper::class),
			Server::get(PairingMapper::class),
		);
	}

	/**
	 * @param list<string> $names
	 */
	private function addGuests(array $names): void {
		foreach ($names as $name) {
			$this->participants[$name] = $this->participantService->addGuest($this->list, $name, $name . '@example.com');
		}
	}

	/**
	 * @return array<string, string> Name of the participant => name of the partner from the sent emails
	 */
	private function takeSentPartners(): array {
		$partners = [];
		foreach ($this->messages as $message) {
			$this->assertSame(1, preg_match('/^PARTNER: (\S+)$/m', $message->getPlainBody(), $matches));
			$partners[(string)current($message->getTo())] = $matches[1];
		}
		$this->messages = [];
		ksort($partners);
		return $partners;
	}

	public function testDraw(): void {
		$this->addGuests(['alice', 'bob', 'carol', 'dave']);
		Server::get(ExclusionService::class)->setExclusions($this->list, $this->participants['alice'], [$this->participants['bob']->getId()], true);

		$draw = $this->createManager()->draw($this->list);

		$partners = $this->takeSentPartners();
		$this->assertSame(['alice', 'bob', 'carol', 'dave'], array_keys($partners));
		$this->assertSame(['alice', 'bob', 'carol', 'dave'], (static function (array $receivers): array {
			sort($receivers);
			return $receivers;
		})(array_values($partners)));
		foreach ($partners as $giver => $receiver) {
			$this->assertNotSame($giver, $receiver);
			$this->assertNotSame($giver, $partners[$receiver]);
		}
		$this->assertNotSame('bob', $partners['alice']);
		$this->assertNotSame('alice', $partners['bob']);

		$serialized = $this->createManager()->serialize($this->list, $draw);
		$this->assertSame(['id', 'createdAt', 'outdated', 'notifications'], array_keys($serialized));
		$this->assertFalse($serialized['outdated']);
		$this->assertSame($draw->getId(), $serialized['id']);
		$this->assertEqualsWithDelta(time(), $serialized['createdAt'], 60);
		$participantIds = array_map(static fn (Participant $participant): string => $participant->getId(), array_values($this->participants));
		sort($participantIds);
		$this->assertSame(
			array_map(static fn (string $id): array => ['participantId' => $id, 'notifiedAt' => self::TIME, 'emailChanged' => false], $participantIds),
			$serialized['notifications'],
		);
		$this->assertSame(
			['alice@example.com', 'bob@example.com', 'carol@example.com', 'dave@example.com'],
			(static function (array $emails): array {
				sort($emails);
				return $emails;
			})(array_map(static fn (Pairing $pairing): ?string => $pairing->notifiedEmail, Server::get(PairingMapper::class)->getPairings($draw))),
		);
		$this->assertSame([$draw->getId()], array_map(static fn ($draw) => $draw->getId(), $this->createManager()->getDraws($this->list)));
	}

	public function testDrawRespectsImportedExclusions(): void {
		$this->addGuests(['alice', 'bob', 'carol', 'dave', 'erin']);
		$manager = $this->createManager();

		$manager->draw($this->list);
		$first = $this->takeSentPartners();
		Server::get(ExclusionService::class)->importDraw($this->list, $this->list);
		$manager->draw($this->list);
		$second = $this->takeSentPartners();

		foreach ($first as $giver => $receiver) {
			$this->assertNotSame($receiver, $second[$giver]);
		}
		$this->assertCount(2, $manager->getDraws($this->list));
	}

	public function testThreeParticipantsCanDrawAgain(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		$manager = $this->createManager();

		$manager->draw($this->list);
		$manager->draw($this->list);

		$this->assertCount(2, $manager->getDraws($this->list));
	}

	public function testDrawIsRefusedIfItIsTheOnlyValidDraw(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		Server::get(ExclusionService::class)->setExclusions($this->list, $this->participants['alice'], [$this->participants['bob']->getId()], false);

		try {
			$this->createManager()->draw($this->list);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('Only one valid draw exists.', $error->getMessage());
		}
		$this->assertSame([], $this->messages);
		$this->assertSame([], $this->createManager()->getDraws($this->list));
	}

	public function testParticipantWithoutEmail(): void {
		$userManager = Server::get(IUserManager::class);
		$userManager->get(self::USER)?->delete();
		$user = $userManager->createUser(self::USER, bin2hex(random_bytes(16)) . 'Aa1!');
		$this->assertInstanceOf(IUser::class, $user);
		$user->setDisplayName('Bob Builder');
		$this->addGuests(['alice', 'carol']);
		$this->participantService->addUser($this->list, self::USER, self::OWNER);

		try {
			$this->createManager()->draw($this->list);
			$this->fail('Expected an exception');
		} catch (InvalidInputException $error) {
			$this->assertSame('Participant without email address.', $error->getMessage());
			$this->assertSame('Bob Builder has no email address and can not be notified.', $error->getHint());
		}
		$this->assertSame([], $this->messages);
		$this->assertSame([], $this->createManager()->getDraws($this->list));
	}

	public function testTooFewParticipants(): void {
		$this->addGuests(['alice', 'bob']);

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Too few participants.');
		try {
			$this->createManager()->draw($this->list);
		} finally {
			$this->assertSame([], $this->createManager()->getDraws($this->list));
		}
	}

	public function testFailedEmailIsStoredAndLogged(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		$this->failingAddresses = ['bob@example.com'];
		$logger = $this->createMock(LoggerInterface::class);
		$this->logger = $logger;
		$logger->expects($this->once())
			->method('error')
			->with('Could not send the email of a draw to a participant.', $this->callback(static fn (array $context): bool => $context['exception'] instanceof \RuntimeException));

		$manager = $this->createManager();
		$draw = $manager->draw($this->list);

		$this->assertCount(3, $this->messages);
		$notifiedAt = [];
		foreach ($manager->serialize($this->list, $draw)['notifications'] as $notification) {
			$notifiedAt[$notification['participantId']] = $notification['notifiedAt'];
		}
		$this->assertNull($notifiedAt[$this->participants['bob']->getId()]);
		$this->assertSame(self::TIME, $notifiedAt[$this->participants['alice']->getId()]);
		$this->assertSame(self::TIME, $notifiedAt[$this->participants['carol']->getId()]);
	}

	public function testNotifyAgain(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		$this->failingAddresses = ['bob@example.com'];
		$manager = $this->createManager();
		$draw = $manager->draw($this->list);
		$partnerOfBob = $this->takeSentPartners()['bob'];
		$this->failingAddresses = [];

		$manager->notify($this->list, $draw, $this->participants['bob'], self::OWNER);

		$this->assertSame(['bob' => $partnerOfBob], $this->takeSentPartners());
		$this->assertSame([self::TIME, self::TIME, self::TIME], array_column($manager->serialize($this->list, $draw)['notifications'], 'notifiedAt'));
	}

	public function testNotifyChangedAddressSendsANoticeToThePreviousAddress(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		$manager = $this->createManager();
		$draw = $manager->draw($this->list);
		$partnerOfBob = $this->takeSentPartners()['bob'];

		$this->participantService->updateGuest($this->list, $this->participants['bob'], 'bob', 'mallory@example.com');
		$this->assertSame([false, true, false], $this->getEmailChanged($manager->serialize($this->list, $draw)));
		$manager->notify($this->list, $draw, $this->participants['bob'], self::OWNER);

		$this->assertCount(2, $this->messages);
		[$notice, $email] = $this->messages;
		$this->assertSame(['bob@example.com' => 'bob'], $notice->getTo(), 'The notice is sent first, to the previous address');
		$this->assertSame('translated: Your Secret Santa email is sent to a different address', $notice->getSubject());
		$this->assertStringContainsString('translated: ' . self::OWNER . ' is sending your email of the Secret Santa "Christmas"', $notice->getPlainBody());
		$this->assertStringNotContainsString('mallory', $notice->getPlainBody(), 'The new address is not sent to the previous one');
		$this->assertStringNotContainsString($partnerOfBob, $notice->getPlainBody());
		$this->assertSame(['mallory@example.com' => 'bob'], $email->getTo());
		$this->assertStringContainsString('translated: This email was sent to a different email address before.', $email->getPlainBody());
		$this->messages = [$email];
		$this->assertSame(['bob' => $partnerOfBob], $this->takeSentPartners());
		$this->assertSame([false, false, false], $this->getEmailChanged($manager->serialize($this->list, $draw)));

		$manager->notify($this->list, $draw, $this->participants['bob'], self::OWNER);
		$this->assertCount(1, $this->messages, 'No notice if the address did not change since the last email');
		$this->assertStringNotContainsString('different email address', $this->messages[0]->getPlainBody());
	}

	public function testNotifyIgnoresTheCaseOfTheAddress(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		$manager = $this->createManager();
		$draw = $manager->draw($this->list);
		$this->messages = [];

		$this->participantService->updateGuest($this->list, $this->participants['bob'], 'bob', 'Bob@Example.com');
		$this->assertSame([false, false, false], $this->getEmailChanged($manager->serialize($this->list, $draw)));
		$manager->notify($this->list, $draw, $this->participants['bob'], self::OWNER);

		$this->assertCount(1, $this->messages);
		$this->assertSame(['Bob@Example.com' => 'bob'], $this->messages[0]->getTo());
		$this->assertStringNotContainsString('different email address', $this->messages[0]->getPlainBody());
	}

	public function testNotifyChangedAddressIsNotSentIfTheNoticeFails(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		$manager = $this->createManager();
		$draw = $manager->draw($this->list);
		$this->messages = [];
		$this->participantService->updateGuest($this->list, $this->participants['bob'], 'bob', 'mallory@example.com');
		$this->failingAddresses = ['bob@example.com'];

		try {
			$manager->notify($this->list, $draw, $this->participants['bob'], self::OWNER);
			$this->fail('Expected an exception');
		} catch (\RuntimeException) {
		}

		$this->assertSame([['bob@example.com' => 'bob']], array_map(static fn (Message $message): array => $message->getTo(), $this->messages));
		$this->assertSame([false, true, false], $this->getEmailChanged($manager->serialize($this->list, $draw)));
	}

	public function testNotifyFailure(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		$manager = $this->createManager();
		$draw = $manager->draw($this->list);
		$this->failingAddresses = ['bob@example.com'];

		$this->expectException(\RuntimeException::class);
		$manager->notify($this->list, $draw, $this->participants['bob'], self::OWNER);
	}

	public function testPartnerIsNotLoggedIfNotifyFails(): void {
		$this->addGuests(['alice', 'bob', 'carol', 'dave']);
		$manager = $this->createManager();
		$draw = $manager->draw($this->list);
		$partner = $this->takeSentPartners()['bob'];
		$this->failingAddresses = ['bob@example.com'];

		$log = $this->logThrownException(fn () => $manager->notify($this->list, $draw, $this->participants['bob'], self::OWNER));
		$this->assertStringContainsString('bob@example.com', $log, 'The arguments are logged');
		$this->assertStringNotContainsString($partner, $log);
		$this->assertStringNotContainsString($this->participants[$partner]->getId(), $log);
	}

	public function testNotifyOnlyForTheLatestDraw(): void {
		$this->addGuests(['alice', 'bob', 'carol', 'dave', 'erin']);
		$manager = $this->createManager();
		$first = $manager->draw($this->list);
		$manager->draw($this->list);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Only the emails of the latest draw can be sent again.');
		$manager->notify($this->list, $first, $this->participants['bob'], self::OWNER);
	}

	public function testNotifyOnlyForParticipantsOfTheDraw(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		$manager = $this->createManager();
		$draw = $manager->draw($this->list);
		$this->addGuests(['dave']);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The participant did not take part in the draw.');
		$manager->notify($this->list, $draw, $this->participants['dave'], self::OWNER);
	}

	public function testDeletingAParticipantMarksTheDrawOutdated(): void {
		$this->addGuests(['alice', 'bob', 'carol', 'dave']);
		$manager = $this->createManager();
		$draw = $manager->draw($this->list);

		$this->participantService->delete($this->participants['alice']);

		$pairings = Server::get(PairingMapper::class)->getPairings($draw);
		$this->assertCount(3, $pairings, 'Only the pairing of alice as giver is deleted, deleting the other one would reveal who drew alice');
		$this->assertNotContains($this->participants['alice']->getId(), array_map(static fn ($pairing) => $pairing->giverId, $pairings));
		$serialized = $manager->serialize($this->list, $draw);
		$this->assertTrue($serialized['outdated']);
		$this->assertCount(3, $serialized['notifications']);

		// Every participant gets the same error, so it does not reveal who drew alice
		foreach (['bob', 'carol', 'dave'] as $name) {
			try {
				$manager->notify($this->list, $draw, $this->participants[$name], self::OWNER);
				$this->fail('Expected an exception');
			} catch (\InvalidArgumentException $error) {
				$this->assertSame('Participants of the draw were removed, the partners need to be drawn again.', $error->getMessage());
			}
		}
	}

	/**
	 * @param array{notifications: list<array{participantId: string, emailChanged: bool, ...}>, ...} $draw
	 * @return list<bool> If the address changed, ordered like alice, bob, carol
	 */
	private function getEmailChanged(array $draw): array {
		$changed = array_column($draw['notifications'], 'emailChanged', 'participantId');
		return array_map(fn (string $name): bool => $changed[$this->participants[$name]->getId()], ['alice', 'bob', 'carol']);
	}

	public function testDeletingAListDeletesItsDraws(): void {
		$this->addGuests(['alice', 'bob', 'carol']);
		$manager = $this->createManager();
		$draw = $manager->draw($this->list);

		$this->listService->delete($this->list);

		$this->assertSame([], $manager->getDraws($this->list));
		$this->assertSame([], Server::get(PairingMapper::class)->getPairings($draw));
	}
}
