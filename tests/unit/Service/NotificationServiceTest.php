<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Tests\Unit\Service;

use OC\Mail\Message;
use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Service\ListService;
use OCA\SecretSanta\Service\NotificationService;
use OCA\SecretSanta\Service\Recipient;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Server;
use OCP\Util;
use PHPUnit\Framework\TestCase;

final class NotificationServiceTest extends TestCase {

	private const string OWNER = 'secret-santa-notification-test';

	private ListService $listService;
	private IUser $owner;
	private SecretSantaList $list;
	/** @var list<Message> */
	private array $messages = [];
	/** @var \Closure(Message): list<string> */
	private \Closure $send;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$userManager = Server::get(IUserManager::class);
		$userManager->get(self::OWNER)?->delete();
		$owner = $userManager->createUser(self::OWNER, bin2hex(random_bytes(16)) . 'Aa1!');
		$this->assertInstanceOf(IUser::class, $owner);
		$this->owner = $owner;
		$this->owner->setDisplayName('Olivia Owner');
		$this->listService = Server::get(ListService::class);
		$this->list = $this->listService->create(self::OWNER, 'Christmas');
		$this->send = static fn (Message $message): array => [];
	}

	#[\Override]
	protected function tearDown(): void {
		$this->listService->deleteByOwner(self::OWNER);
		$this->owner->delete();
		parent::tearDown();
	}

	private function createService(): NotificationService {
		$mailer = Server::get(IMailer::class);
		$stub = $this->createStub(IMailer::class);
		$stub->method('createEMailTemplate')->willReturnCallback(static fn (string $id, array $data = []) => $mailer->createEMailTemplate($id, $data));
		$stub->method('createMessage')->willReturnCallback(static fn () => $mailer->createMessage());
		$stub->method('send')->willReturnCallback(function (Message $message): array {
			$this->messages[] = $message;
			return ($this->send)($message);
		});
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));
		return new NotificationService($l10n, $stub, Server::get(IUserManager::class));
	}

	public function testSend(): void {
		$this->owner->setSystemEMailAddress('olivia@example.com');
		$this->listService->update(
			$this->list,
			senderName: 'Santa',
			mailSubject: 'A gift from {name}',
			mailBody: "Hello {name},\n\nplease give <b>{partner}</b> a gift!\nSee you\n\n\nSanta",
		);

		$this->createService()->send($this->list, new Recipient('Alice', 'alice@example.com'), new Recipient('Bob & Co', 'bob@example.com'));

		$this->assertCount(1, $this->messages);
		$message = $this->messages[0];
		$this->assertSame(['alice@example.com' => 'Alice'], $message->getTo());
		$this->assertSame([Util::getDefaultEmailAddress('noreply') => 'Santa'], $message->getFrom());
		$this->assertSame(['olivia@example.com' => 'Olivia Owner'], $message->getReplyTo());
		$this->assertSame('A gift from Alice', $message->getSubject());
		$this->assertStringContainsString("Hello Alice,\n\nplease give <b>Bob & Co</b> a gift!\nSee you\n\nSanta", $message->getPlainBody());
		$html = (string)$message->getSymfonyEmail()->getHtmlBody();
		$this->assertStringContainsString('please give &lt;b&gt;Bob &amp; Co&lt;/b&gt; a gift!<br>', $html);
		$this->assertStringNotContainsString('<b>Bob', $html);
		$this->assertStringNotContainsString('bob@example.com', $html . $message->getPlainBody(), 'The email address of the partner is not sent');
		$this->assertStringContainsString("-- \ntranslated: Replies to this email are sent to the organizer of the Secret Santa.", $message->getPlainBody());
		$this->assertStringNotContainsString('please do not reply', $message->getPlainBody());
	}

	public function testSenderIsOwnerWithoutReplyToIfOwnerHasNoEmail(): void {
		$this->createService()->send($this->list, new Recipient('Alice', 'alice@example.com'), new Recipient('Bob', 'bob@example.com'));

		$this->assertSame([Util::getDefaultEmailAddress('noreply') => 'Olivia Owner'], $this->messages[0]->getFrom());
		$this->assertSame([], $this->messages[0]->getReplyTo());
		$this->assertStringContainsString('please do not reply', $this->messages[0]->getPlainBody());
	}

	public function testPlaceholdersInNamesAreNotReplaced(): void {
		$this->listService->update($this->list, mailSubject: 'Hi {name}', mailBody: '{name} gives {partner}');

		$this->createService()->send($this->list, new Recipient('{partner}', 'alice@example.com'), new Recipient('{name}', 'bob@example.com'));

		$this->assertSame('Hi {partner}', $this->messages[0]->getSubject());
		$this->assertStringContainsString("{partner} gives {name}\n", $this->messages[0]->getPlainBody());
	}

	public function testPartnerIsNotReplacedInTheSubject(): void {
		// Not possible through ListService::update(), the subject may only be stored by an older version
		$this->list->setMailSubject('{partner}');

		$this->createService()->send($this->list, new Recipient('Alice', 'alice@example.com'), new Recipient('Bob', 'bob@example.com'));

		$this->assertSame('{partner}', $this->messages[0]->getSubject());
	}

	public function testFailedDelivery(): void {
		$this->send = static fn (Message $message): array => array_keys($message->getTo());

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('The email could not be delivered.');
		$this->createService()->send($this->list, new Recipient('Alice', 'alice@example.com'), new Recipient('Bob', 'bob@example.com'));
	}

	public function testMailerError(): void {
		$this->send = static fn (Message $message): array => throw new \Exception('Connection refused');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('The email could not be sent.');
		$this->createService()->send($this->list, new Recipient('Alice', 'alice@example.com'), new Recipient('Bob', 'bob@example.com'));
	}
}
