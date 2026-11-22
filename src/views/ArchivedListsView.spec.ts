/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import ArchivedListsView from './ArchivedListsView.vue'
import { useListsStore } from '../store/lists.ts'
import { createOcsError, createTestList } from '../test-utils.ts'

const api = vi.hoisted(() => ({
	fetchLists: vi.fn(),
	unarchiveList: vi.fn(),
}))
vi.mock('../api/index.ts', () => api)
const { spawnDialog } = vi.hoisted(() => ({ spawnDialog: vi.fn() }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'admin', displayName: 'Olivia Owner', isAdmin: false }) }))

const active = createTestList({ id: '10', name: 'Christmas' })
const lastYear = createTestList({ id: '11', name: 'Last year', archivedAt: 1_766_000_000 })
const older = createTestList({ id: '12', name: 'Two years ago', archivedAt: 1_734_000_000 })

/**
 * Mount the view with a router, as the archived lists link to their page
 */
async function mountView() {
	const router = createRouter({
		history: createMemoryHistory(),
		routes: [
			{ path: '/archived', name: 'archived', component: ArchivedListsView },
			{ path: '/:listId(\\d+)', name: 'list', component: { template: '<div />' } },
		],
	})
	await router.push({ name: 'archived' })
	const wrapper = mount(ArchivedListsView, { attachTo: document.body, global: { plugins: [router] } })
	await flushPromises()
	return wrapper
}

/**
 * Open the actions menu of an archived list and click an action
 *
 * @param wrapper - The mounted view
 * @param listId - The id of the list
 * @param label - The label of the action
 */
async function clickAction(wrapper: Awaited<ReturnType<typeof mountView>>, listId: string, label: string) {
	await wrapper.find(`[data-list="${listId}"] .action-item button`).trigger('click')
	await flushPromises()
	vi.runOnlyPendingTimers()
	await flushPromises()
	const action = wrapper.findAllComponents(NcActionButton).find((button) => button.text() === label)!
	await action.vm.$emit('click', new MouseEvent('click'))
	await flushPromises()
}

describe('ArchivedListsView', () => {
	beforeEach(() => {
		vi.useFakeTimers({ now: 1_766_100_000_000 })
		setActivePinia(createPinia())
		api.fetchLists.mockReset()
		api.unarchiveList.mockReset()
		spawnDialog.mockReset()
		api.fetchLists.mockResolvedValue([older, active, lastYear])
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('shows the archived lists, the latest archived first', async () => {
		const wrapper = await mountView()

		const items = wrapper.findAll('[data-list]')
		expect(items.map((item) => item.attributes('data-list'))).toEqual(['11', '12'])
		expect(items[0]!.text()).toContain('Last year')
		expect(items[0]!.find('a').attributes('href')).toBe('/11')
	})

	it('shows an empty view without archived lists', async () => {
		api.fetchLists.mockResolvedValue([active])

		const wrapper = await mountView()

		expect(wrapper.text()).toContain('No archived lists')
		expect(wrapper.findAll('[data-list]')).toHaveLength(0)
	})

	it('shows an error if the lists could not be loaded', async () => {
		api.fetchLists.mockRejectedValue(createOcsError(500))

		const wrapper = await mountView()

		expect(wrapper.text()).toContain('Could not load the lists.')
	})

	it('unarchives a list', async () => {
		api.unarchiveList.mockResolvedValue({ ...lastYear, archivedAt: null })
		const wrapper = await mountView()

		await clickAction(wrapper, '11', 'Unarchive')

		expect(api.unarchiveList).toHaveBeenCalledWith('11')
		expect(wrapper.findAll('[data-list]').map((item) => item.attributes('data-list'))).toEqual(['12'])
		expect(useListsStore().activeLists.map(({ id }) => id)).toEqual(['10', '11'])
	})

	it('shows why a list could not be unarchived', async () => {
		api.unarchiveList.mockRejectedValue(createOcsError(500))
		const wrapper = await mountView()

		await clickAction(wrapper, '11', 'Unarchive')

		expect(wrapper.text()).toContain('Could not unarchive Last year.')
		expect(wrapper.findAll('[data-list]')).toHaveLength(2)
	})

	it('deletes a list after confirmation', async () => {
		spawnDialog.mockResolvedValue(true)
		const wrapper = await mountView()

		await clickAction(wrapper, '12', 'Delete')

		expect(spawnDialog).toHaveBeenCalledWith(expect.anything(), { list: older })
		expect(wrapper.findAll('[data-list]').map((item) => item.attributes('data-list'))).toEqual(['11'])
	})

	it('does not offer to delete lists of other users', async () => {
		api.fetchLists.mockResolvedValue([{ ...lastYear, owner: 'bob', ownerDisplayName: 'Bob' }])
		const wrapper = await mountView()

		const item = wrapper.find('[data-list="11"]')
		expect(item.html()).toContain('Unarchive')
		expect(item.html()).not.toContain('Delete')
	})

	it('keeps a list if the deletion was canceled', async () => {
		spawnDialog.mockResolvedValue(false)
		const wrapper = await mountView()

		await clickAction(wrapper, '12', 'Delete')

		expect(wrapper.findAll('[data-list]')).toHaveLength(2)
	})
})
