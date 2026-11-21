<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

use OCA\SecretSanta\Db\SecretSantaList;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Util;
use RuntimeException;

/**
 * Sends the participants the partner they give a gift to
 */
final class NotificationService {

	/** Placeholder of the name of the participant in the subject and text of the email */
	public const string PLACEHOLDER_NAME = '{name}';
	/** Placeholder of the name of the partner in the text of the email, not allowed in the subject as subjects are often logged */
	public const string PLACEHOLDER_PARTNER = '{partner}';

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IL10N $l10n,
		private IMailer $mailer,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Send the email of the list to the giver, telling them the receiver of their gift.
	 *
	 * The email is sent from the default address of the instance with the sender name of the list,
	 * replies go to the owner of the list if they have an email address.
	 *
	 * @throws RuntimeException If the email could not be sent
	 */
	public function send(SecretSantaList $list, Recipient $giver, Recipient $receiver): void {
		$placeholders = [
			self::PLACEHOLDER_NAME => $giver->displayName,
			self::PLACEHOLDER_PARTNER => $receiver->displayName,
		];
		$subject = str_replace(self::PLACEHOLDER_NAME, $giver->displayName, $list->getMailSubject());
		$body = strtr($list->getMailBody(), $placeholders);

		$template = $this->mailer->createEMailTemplate('secret_santa.Draw', [
			'list' => $list->getName(),
		]);
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($subject);
		$paragraphs = preg_split('/\R\s*\R/', trim($body)) ?: [];
		foreach ($paragraphs as $paragraph) {
			$template->addBodyText(nl2br(htmlspecialchars($paragraph), false), $paragraph);
		}

		$owner = $this->userManager->get($list->getOwner());
		$ownerName = $owner?->getDisplayName() ?? $list->getOwner();
		$ownerEmail = $owner?->getEMailAddress() ?? '';
		$message = $this->mailer->createMessage();
		$message->setFrom([Util::getDefaultEmailAddress('noreply') => $list->getSenderName() ?? $ownerName]);
		if ($ownerEmail !== '') {
			$message->setReplyTo([$ownerEmail => $ownerName]);
			// The default footer asks not to reply
			$template->addFooter(htmlspecialchars($this->l10n->t('Replies to this email are sent to the organizer of the Secret Santa.'), ENT_NOQUOTES));
		} else {
			$template->addFooter();
		}
		$message->setTo([$giver->email => $giver->displayName]);
		$message->useTemplate($template);

		try {
			$failed = $this->mailer->send($message);
		} catch (\Exception $error) {
			throw new RuntimeException('The email could not be sent.', previous: $error);
		}
		if ($failed !== []) {
			throw new RuntimeException('The email could not be delivered.');
		}
	}
}
