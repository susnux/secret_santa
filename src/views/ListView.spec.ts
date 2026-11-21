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
	unarchiveList: vi.fn(),
}))
vi.mock('../api/index.ts', () => api)
const { replace } = vi.hoisted(() => ({ replace: vi.fn() }))
vi.mock('vue-router', async (importOriginal) => ({ ...await importOriginal<Record<string, unknown>>(), useRouter: () => ({ replace }) }))

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

	it('ignores the data of a list loaded after another list was opened', async () => {
		api.fetchLists.mockResolvedValue([list, createTestList({ id: '11', name: 'Birthday' })])
		let resolveFirst: (value: unknown) => void = () => {}
		api.fetchParticipants.mockImplementation((listId: string) => listId === '10'
			? new Promise((resolve) => {
					resolveFirst = resolve
				})
			: Promise.resolve([createTestGuest('2', 'Bob')]))
		const wrapper = await mountView('10')

		await wrapper.setProps({ listId: '11' })
		await flushPromises()
		resolveFirst([createTestGuest('1', 'Alice')])
		await flushPromises()

		expect(wrapper.find('h2').text()).toBe('Birthday')
		expect(wrapper.findComponent({ name: 'ParticipantsSection' }).props('participants')).toEqual([createTestGuest('2', 'Bob')])
	})

	it('shows an error if the lists could not be loaded', async () => {
		api.fetchLists.mockRejectedValue(createOcsError(500))

		const wrapper = await mountView('10')

		expect(wrapper.text()).toContain('Could not load the list.')
	})

	it('shows that an archived list can not be changed and unarchives it', async () => {
		api.fetchLists.mockResolvedValue([{ ...list, archivedAt: 1_766_000_000 }])
		api.unarchiveList.mockResolvedValue(list)
		const wrapper = await mountView('10')

		expect(wrapper.text()).toContain('This list is archived and can not be changed.')
		const button = wrapper.findAll('button').find((element) => element.text() === 'Unarchive')!
		await button.trigger('click')
		await flushPromises()

		expect(api.unarchiveList).toHaveBeenCalledWith('10')
		expect(wrapper.text()).not.toContain('This list is archived')
		expect(wrapper.findComponent({ name: 'ParticipantsSection' }).props('list')).toEqual(list)
	})

	it('shows why an archived list could not be unarchived', async () => {
		api.fetchLists.mockResolvedValue([{ ...list, archivedAt: 1_766_000_000 }])
		api.unarchiveList.mockRejectedValue(createOcsError(500))
		const wrapper = await mountView('10')

		await wrapper.findAll('button').find((element) => element.text() === 'Unarchive')!.trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Could not unarchive the list.')
		expect(wrapper.text()).toContain('This list is archived')
	})

	it('shows an error if the list could not be loaded', async () => {
		api.fetchDraws.mockRejectedValue(createOcsError(500))

		const wrapper = await mountView('10')

		expect(wrapper.text()).toContain('Could not load the list.')
		expect(replace).not.toHaveBeenCalled()
	})
})
