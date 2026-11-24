/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import SharingSection from './SharingSection.vue'
import TakeOverListDialog from './TakeOverListDialog.vue'
import { clickButton, createOcsError, createTestList } from '../test-utils.ts'

const api = vi.hoisted(() => ({
	fetchShares: vi.fn(),
	searchGroups: vi.fn(),
	shareList: vi.fn(),
	unshareList: vi.fn(),
}))
vi.mock('../api/index.ts', () => api)
const { spawnDialog } = vi.hoisted(() => ({ spawnDialog: vi.fn() }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'admin', displayName: 'Olivia Owner', isAdmin: false }) }))

const list = createTestList()
const family = { groupId: 'family', displayName: 'Family' }

/**
 * Mount the section and wait for the shares to load
 *
 * @param currentList - The list shown in the section
 */
async function mountSection(currentList = list) {
	const wrapper = mount(SharingSection, { attachTo: document.body, props: { list: currentList } })
	await flushPromises()
	return wrapper
}

describe('SharingSection', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		Object.values(api).forEach((mock) => mock.mockReset())
		spawnDialog.mockReset()
		api.fetchShares.mockResolvedValue([family])
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('shows the groups the list is shared with', async () => {
		const wrapper = await mountSection()

		expect(api.fetchShares).toHaveBeenCalledWith(list.id)
		expect(wrapper.find('[data-group="family"]').text()).toContain('Family')
		expect(wrapper.text()).toContain('Only you can delete and share the list.')
		expect(wrapper.text()).not.toContain('Take over list')
	})

	it('shows that the list is not shared', async () => {
		api.fetchShares.mockResolvedValue([])

		const wrapper = await mountSection()

		expect(wrapper.text()).toContain('The list is not shared.')
	})

	it('searches groups the list is not shared with', async () => {
		api.searchGroups.mockResolvedValue([{ id: 'family', label: 'Family' }, { id: 'friends', label: 'Friends' }])
		const wrapper = await mountSection()

		await wrapper.findComponent(NcSelect).vm.$emit('search', 'f')
		await wrapper.findComponent(NcSelect).vm.$emit('search', 'fr')
		vi.advanceTimersByTime(300)
		await flushPromises()

		expect(api.searchGroups).toHaveBeenCalledTimes(1)
		expect(api.searchGroups).toHaveBeenCalledWith('fr')
		expect(wrapper.findComponent(NcSelect).props()).toMatchObject({ options: [{ id: 'friends', label: 'Friends' }] })
	})

	it('shares the list with the selected group', async () => {
		api.shareList.mockResolvedValue({ groupId: 'friends', displayName: 'Friends' })
		const wrapper = await mountSection()

		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { id: 'friends', label: 'Friends' })
		await flushPromises()

		expect(api.shareList).toHaveBeenCalledWith(list.id, 'friends')
		expect(wrapper.findAll('[data-group]').map((item) => item.attributes('data-group'))).toEqual(['family', 'friends'])
	})

	it('shows why the list could not be shared', async () => {
		api.shareList.mockRejectedValue(createOcsError(400, 'Sharing is only allowed within your own groups.'))
		const wrapper = await mountSection()

		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { id: 'friends', label: 'Friends' })
		await flushPromises()

		expect(wrapper.text()).toContain('Sharing is only allowed within your own groups.')
		expect(wrapper.findAll('[data-group]')).toHaveLength(1)
	})

	it('stops sharing with a group', async () => {
		api.unshareList.mockResolvedValue(undefined)
		const wrapper = await mountSection()

		await wrapper.find('button[aria-label="Stop sharing with Family"]').trigger('click')
		await flushPromises()

		expect(api.unshareList).toHaveBeenCalledWith(list.id, 'family')
		expect(wrapper.findAll('[data-group]')).toHaveLength(0)
	})

	it('only shows the groups to members of the groups', async () => {
		const wrapper = await mountSection(createTestList({ owner: 'bob', ownerDisplayName: 'Bob' }))

		expect(wrapper.find('[data-group="family"]').exists()).toBe(true)
		expect(wrapper.findComponent(NcSelect).exists()).toBe(false)
		expect(wrapper.find('button[aria-label="Stop sharing with Family"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Shared by Bob.')
		expect(wrapper.text()).toContain('Only Bob can delete and share the list.')
		expect(wrapper.text()).not.toContain('Take over list')
	})

	it('explains lists whose owner was deleted', async () => {
		const wrapper = await mountSection(createTestList({ owner: null, ownerDisplayName: null }))

		expect(wrapper.text()).toContain('The owner of this list was deleted.')
		expect(wrapper.findComponent(NcSelect).exists()).toBe(false)
	})

	it('takes over lists whose owner was deleted', async () => {
		const orphan = createTestList({ owner: null, ownerDisplayName: null })
		const takenOver = createTestList()
		spawnDialog.mockResolvedValue(takenOver)
		const wrapper = await mountSection(orphan)

		await clickButton('Take over list')

		expect(spawnDialog).toHaveBeenCalledWith(TakeOverListDialog, { list: orphan })
		expect(wrapper.emitted('update:list')).toEqual([[takenOver]])
	})

	it('keeps the list if taking over was cancelled', async () => {
		spawnDialog.mockResolvedValue(undefined)
		const wrapper = await mountSection(createTestList({ owner: null, ownerDisplayName: null }))

		await clickButton('Take over list')

		expect(wrapper.emitted('update:list')).toBeUndefined()
	})

	it('shows an error if the shares could not be loaded', async () => {
		api.fetchShares.mockRejectedValue(createOcsError(500))

		const wrapper = await mountSection()

		expect(wrapper.text()).toContain('Could not load the groups the list is shared with.')
	})
})
