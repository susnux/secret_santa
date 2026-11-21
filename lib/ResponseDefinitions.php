<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta;

/**
 * Ids are Snowflake IDs, sent as strings as they exceed the safe integer range of JavaScript.
 * `senderName` is the display name of the sender of the emails, the display name of the owner is used if it is null.
 * `mailSubject` and `mailBody` are the email sent to every participant, `{name}` is replaced by the name of the
 * participant and `{partner}` by the name of the participant to give a gift to.
 *
 * @psalm-type SecretSantaList = array{
 *     id: string,
 *     name: string,
 *     senderName: ?string,
 *     mailSubject: string,
 *     mailBody: string,
 * }
 *
 * `userId` is set for Nextcloud users and null for guests.
 * `email` is only set for guests, as the email address of users is private.
 * `hasEmail` is false for users without an email address, who can not be notified.
 *
 * @psalm-type SecretSantaParticipant = array{
 *     id: string,
 *     userId: ?string,
 *     displayName: string,
 *     email: ?string,
 *     hasEmail: bool,
 * }
 *
 * The participant `giverId` must not draw the participant `receiverId` as their partner.
 *
 * @psalm-type SecretSantaExclusion = array{
 *     giverId: string,
 *     receiverId: string,
 * }
 *
 * @psalm-suppress UnusedClass Only used as a container for the response type definitions
 */
final class ResponseDefinitions {
}
