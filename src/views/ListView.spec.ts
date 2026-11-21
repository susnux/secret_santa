/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ListView from './ListView.vue'
import { createOcsError, createTestGuest, createTestList } from '../test-utils.ts'

const api = vi.hoisted(() => ({
	fetchLists: vi.fn(),
	fetchParticipants: vi.fn(),
	fetchExclusions: vi.fn(),
	fetchDraws: vi.fn(),
}))
vi.mock('../api/index.ts', () => api)
const { replace } = vi.hoisted(() => ({ replace: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => ({ replace }) }))

const list = createTestList({ id: '10', name: 'Christmas' })

/**
 * Mount the view of a list, the sections are stubbed
 *
 * @param listId - The id of the list
 */
async function mountView(listId: string) {
	const wrapper = mount(ListView, {
		props: { listId },
		global: { stubs: { ParticipantsSection: true, MailSection: true, DrawSection: true } },
	})
	await flushPromises()
	return wrapper
}

describe('ListView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		Object.values(api).forEach((mock) => mock.mockReset())
		replace.mockReset()
		api.fetchLists.mockResolvedValue([list])
		api.fetchParticipants.mockResolvedValue([createTestGuest('1', 'Alice')])
		api.fetchExclusions.mockResolvedValue([])
		api.fetchDraws.mockResolvedValue([])
	})

	it('loads the participants, exclusions and draws of the list', async () => {
		const wrapper = await mountView('10')

		expect(api.fetchParticipants).toHaveBeenCalledWith('10')
		expect(api.fetchExclusions).toHaveBeenCalledWith('10')
		expect(api.fetchDraws).toHaveBeenCalledWith('10')
		expect(wrapper.find('h2').text()).toBe('Christmas')
		expect(wrapper.findComponent({ name: 'ParticipantsSection' }).props('participants')).toEqual([createTestGuest('1', 'Alice')])
		expect(replace).not.toHaveBeenCalled()
	})

	it('shows "not found" for unknown lists without loading them', async () => {
		await mountView('11')

		expect(replace).toHaveBeenCalledWith({ name: 'not-found' })
		expect(api.fetchParticipants).not.toHaveBeenCalled()
		expect(api.fetchExclusions).not.toHaveBeenCalled()
		expect(api.fetchDraws).not.toHaveBeenCalled()
	})

	it('shows "not found" if the list was deleted in the meantime', async () => {
		api.fetchParticipants.mockRejectedValue(createOcsError(404))

		await mountView('10')

		expect(replace).toHaveBeenCalledWith({ name: 'not-found' })
	})

	it('shows an error if the list could not be loaded', async () => {
		api.fetchDraws.mockRejectedValue(createOcsError(500))

		const wrapper = await mountView('10')

		expect(wrapper.text()).toContain('Could not load the list.')
		expect(replace).not.toHaveBeenCalled()
	})
})
