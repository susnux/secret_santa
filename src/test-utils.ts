/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { IParticipant, ISecretSantaList } from './models/types.ts'

import { flushPromises } from '@vue/test-utils'
import { vi } from 'vitest'

/**
 * Create a list for tests
 *
 * @param values - Values to override
 */
export function createTestList(values: Partial<ISecretSantaList> = {}): ISecretSantaList {
	return {
		id: '10',
		owner: 'admin',
		ownerDisplayName: 'Olivia Owner',
		name: 'Christmas',
		senderName: null,
		mailSubject: 'Hi {name}',
		mailBody: 'Hello {name}, you give {partner} a gift',
		exclusionsImported: false,
		archivedAt: null,
		...values,
	}
}

/**
 * Create a guest for tests
 *
 * @param id - The id of the guest
 * @param displayName - The name of the guest
 */
export function createTestGuest(id: string, displayName: string): IParticipant {
	return { id, userId: null, displayName, email: displayName.toLowerCase() + '@example.com', hasEmail: true }
}

/**
 * Click a button by its text and wait for the result, the tests need fake timers.
 *
 * Only the pending timers run, as some components update themselves with intervals
 *
 * @param label - The text of the button
 */
export async function clickButton(label: string) {
	const button = [...document.querySelectorAll('button')].find((element) => element.textContent?.trim() === label)
	if (!button) {
		throw new Error('Button not found: ' + label)
	}
	button.click()
	await flushPromises()
	vi.runOnlyPendingTimers()
	await flushPromises()
}

/**
 * Create an error like axios throws it for a failed OCS request
 *
 * @param status - The HTTP status code
 * @param message - The message of the OCS meta data
 * @param data - The data of the OCS response
 */
export function createOcsError(status: number, message = '', data: unknown = []) {
	return Object.assign(new Error('Request failed'), {
		isAxiosError: true,
		response: { status, data: { ocs: { meta: { status: 'failure', statuscode: status, message }, data } } },
	})
}
