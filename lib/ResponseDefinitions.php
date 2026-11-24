<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta;

/**
 * Ids are Snowflake IDs, sent as strings as they exceed the safe integer range of JavaScript.
 * `owner` is the user who created the list, only they can delete and share it. Members of the groups the list
 * is shared with can change everything else. `owner` and `ownerDisplayName` are null if the owner was deleted
 * while the list was shared.
 * `senderName` is the display name of the sender of the emails, the display name of the owner is used if it is null.
 * `mailSubject` and `mailBody` are the email sent to every participant, `{name}` is replaced by the name of the
 * participant and, only in `mailBody`, `{partner}` by the name of the participant to give a gift to.
 * `exclusionsImported` is set if the partners of draws were imported as exclusions. These exclusions are only
 * used for drawing and never returned, as they would reveal the partners of those draws.
 * `archivedAt` is the time the list was archived as Unix timestamp, or null if it is not archived.
 * Archived lists can not be changed until they are unarchived, but their draws can still be imported as exclusions.
 *
 * @psalm-type SecretSantaList = array{
 *     id: string,
 *     owner: ?string,
 *     ownerDisplayName: ?string,
 *     name: string,
 *     senderName: ?string,
 *     mailSubject: string,
 *     mailBody: string,
 *     exclusionsImported: bool,
 *     archivedAt: ?int,
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
 * `notifiedAt` is the time the email with their partner was sent to the participant `participantId`,
 * as Unix timestamp, or null if it could not be sent.
 * `emailChanged` is true if the email address of the participant changed since the email was sent.
 *
 * @psalm-type SecretSantaNotification = array{
 *     participantId: string,
 *     notifiedAt: ?int,
 *     emailChanged: bool,
 * }
 *
 * A draw of the partners of a list, `createdAt` is the time of the draw as Unix timestamp.
 * The drawn partners are never returned, they are only sent to the participants.
 * A draw is `outdated` if participants of it were removed, so others have no partner anymore.
 * The emails of an outdated draw can not be sent again, the partners need to be drawn again.
 *
 * @psalm-type SecretSantaDraw = array{
 *     id: string,
 *     createdAt: int,
 *     outdated: bool,
 *     notifications: list<SecretSantaNotification>,
 * }
 *
 * The list is shared with the group `groupId`, all members of the group can change the list.
 *
 * @psalm-type SecretSantaShare = array{
 *     groupId: string,
 *     displayName: string,
 * }
 *
 * @psalm-suppress UnusedClass Only used as a container for the response type definitions
 */
final class ResponseDefinitions {
}
