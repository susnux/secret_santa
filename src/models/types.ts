/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** A list of participants who give each other gifts, see `lib/ResponseDefinitions.php` */
export interface ISecretSantaList {
	id: string
	/** The user who created the list, only they can delete and share it. Null if they were deleted while the list was shared. */
	owner: string | null
	ownerDisplayName: string | null
	name: string
	/** Display name of the sender of the emails, the display name of the owner if null */
	senderName: string | null
	mailSubject: string
	mailBody: string
	/** Partners of draws were imported as exclusions, these exclusions are never sent to the browser */
	exclusionsImported: boolean
	/** Unix timestamp of archiving the list, null if it is not archived. Archived lists can not be changed. */
	archivedAt: number | null
}

/** A participant of a list, either a Nextcloud user or a guest */
export interface IParticipant {
	id: string
	/** The Nextcloud user, null for guests */
	userId: string | null
	displayName: string
	/** The email address of guests, null for users */
	email: string | null
	/** False for users without email address, who can not be notified */
	hasEmail: boolean
}

/** The giver must not draw the receiver */
export interface IExclusion {
	giverId: string
	receiverId: string
}

export interface INotification {
	participantId: string
	/** Unix timestamp of the email with the partner, null if it could not be sent */
	notifiedAt: number | null
}

/** A draw of the partners, the drawn partners are never sent to the browser */
export interface IDraw {
	id: string
	/** Unix timestamp of the draw */
	createdAt: number
	/** Participants of the draw were removed, so the partners need to be drawn again */
	outdated: boolean
	notifications: INotification[]
}

/** The list is shared with a group, all members of the group can change the list */
export interface IShare {
	groupId: string
	displayName: string
}

/** A user or group found by the autocomplete API of the server */
export interface IAutocompleteResult {
	id: string
	label: string
	subline?: string
}
