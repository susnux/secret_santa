/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { AxiosResponse } from '@nextcloud/axios'
import type { OCSResponse } from '@nextcloud/typings/ocs'
import type { IDraw, IExclusion, IParticipant, ISecretSantaList, IUserSearchResult } from '../models/types.ts'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Generate the OCS URL of an API endpoint of the app
 *
 * @param path - The path of the endpoint, parameters are written as `{name}`
 * @param params - The values of the parameters
 */
function apiUrl(path: string, params: Record<string, string> = {}): string {
	return generateOcsUrl('apps/secret_santa/api/v1/' + path, params)
}

/**
 * Get the data of an OCS response
 *
 * @param request - The request returning the OCS response
 */
async function ocsData<T>(request: Promise<AxiosResponse<OCSResponse<T>>>): Promise<T> {
	const { data } = await request
	return data.ocs.data
}

/**
 * Get the lists of the current user
 */
export function fetchLists(): Promise<ISecretSantaList[]> {
	return ocsData(axios.get(apiUrl('lists')))
}

/**
 * Create a new list with the default email
 *
 * @param name - The name of the list
 */
export function createList(name: string): Promise<ISecretSantaList> {
	return ocsData(axios.post(apiUrl('lists'), { name }))
}

/**
 * Change the given values of a list
 *
 * @param listId - The id of the list
 * @param values - The values to change, an empty `senderName` uses the display name of the owner
 */
export function updateList(listId: string, values: Partial<Pick<ISecretSantaList, 'name' | 'mailSubject' | 'mailBody'>> & { senderName?: string }): Promise<ISecretSantaList> {
	return ocsData(axios.put(apiUrl('lists/{listId}', { listId }), values))
}

/**
 * Delete a list including its participants and draws
 *
 * @param listId - The id of the list
 */
export async function deleteList(listId: string): Promise<void> {
	await axios.delete(apiUrl('lists/{listId}', { listId }))
}

/**
 * Get the participants of a list
 *
 * @param listId - The id of the list
 */
export function fetchParticipants(listId: string): Promise<IParticipant[]> {
	return ocsData(axios.get(apiUrl('lists/{listId}/participants', { listId })))
}

/**
 * Add a Nextcloud user to a list
 *
 * @param listId - The id of the list
 * @param userId - The user to add
 */
export function addUser(listId: string, userId: string): Promise<IParticipant> {
	return ocsData(axios.post(apiUrl('lists/{listId}/participants', { listId }), { userId }))
}

/**
 * Add a guest to a list
 *
 * @param listId - The id of the list
 * @param displayName - The name of the guest
 * @param email - The email address of the guest
 */
export function addGuest(listId: string, displayName: string, email: string): Promise<IParticipant> {
	return ocsData(axios.post(apiUrl('lists/{listId}/participants', { listId }), { displayName, email }))
}

/**
 * Change the name and email address of a guest
 *
 * @param listId - The id of the list
 * @param participantId - The id of the guest
 * @param displayName - The name of the guest
 * @param email - The email address of the guest
 */
export function updateGuest(listId: string, participantId: string, displayName: string, email: string): Promise<IParticipant> {
	return ocsData(axios.put(apiUrl('lists/{listId}/participants/{participantId}', { listId, participantId }), { displayName, email }))
}

/**
 * Remove a participant from a list
 *
 * @param listId - The id of the list
 * @param participantId - The id of the participant
 */
export async function removeParticipant(listId: string, participantId: string): Promise<void> {
	await axios.delete(apiUrl('lists/{listId}/participants/{participantId}', { listId, participantId }))
}

/**
 * Get the partners the participants of a list must not draw
 *
 * @param listId - The id of the list
 */
export function fetchExclusions(listId: string): Promise<IExclusion[]> {
	return ocsData(axios.get(apiUrl('lists/{listId}/exclusions', { listId })))
}

/**
 * Set the partners a participant must not draw
 *
 * @param listId - The id of the list
 * @param participantId - The id of the participant
 * @param receiverIds - The ids of the participants this participant must not draw
 * @param mutual - Apply the changes in the other direction as well
 * @return All exclusions of the list
 */
export function setExclusions(listId: string, participantId: string, receiverIds: string[], mutual: boolean): Promise<IExclusion[]> {
	return ocsData(axios.put(apiUrl('lists/{listId}/participants/{participantId}/exclusions', { listId, participantId }), { receiverIds, mutual }))
}

/**
 * Exclude the partners of the latest draw of a list, so nobody draws the same partner again
 *
 * @param listId - The id of the list
 * @param sourceListId - The id of the list whose latest draw is imported, can be the list itself
 * @return The updated list
 */
export function importExclusions(listId: string, sourceListId: string): Promise<ISecretSantaList> {
	return ocsData(axios.post(apiUrl('lists/{listId}/exclusions/import', { listId }), { sourceListId }))
}

/**
 * Remove the exclusions imported from draws
 *
 * @param listId - The id of the list
 * @return The updated list
 */
export function removeImportedExclusions(listId: string): Promise<ISecretSantaList> {
	return ocsData(axios.delete(apiUrl('lists/{listId}/exclusions/imported', { listId })))
}

/**
 * Get the draws of a list, the latest first
 *
 * @param listId - The id of the list
 */
export function fetchDraws(listId: string): Promise<IDraw[]> {
	return ocsData(axios.get(apiUrl('lists/{listId}/draws', { listId })))
}

/**
 * Draw the partners and send every participant an email with their partner
 *
 * @param listId - The id of the list
 */
export function createDraw(listId: string): Promise<IDraw> {
	return ocsData(axios.post(apiUrl('lists/{listId}/draws', { listId })))
}

/**
 * Send the email with their partner to a participant of the latest draw again
 *
 * @param listId - The id of the list
 * @param drawId - The id of the latest draw
 * @param participantId - The id of the participant
 */
export function notifyParticipant(listId: string, drawId: string, participantId: string): Promise<IDraw> {
	return ocsData(axios.post(apiUrl('lists/{listId}/draws/{drawId}/participants/{participantId}/notify', { listId, drawId, participantId })))
}

/**
 * Search the Nextcloud users the current user may share with
 *
 * @param search - The search term
 */
export function searchUsers(search: string): Promise<IUserSearchResult[]> {
	return ocsData(axios.get(generateOcsUrl('core/autocomplete/get'), {
		params: { search, itemType: 'secret_santa', itemId: '', shareTypes: [0], limit: 10 },
	}))
}
