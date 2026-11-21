/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcListItemIcon from '@nextcloud/vue/components/NcListItemIcon'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import ExclusionsDialog from './ExclusionsDialog.vue'
import GuestDialog from './GuestDialog.vue'
import ImportExclusionsDialog from './ImportExclusionsDialog.vue'
import ParticipantsSection from './ParticipantsSection.vue'
import { clickButton, createOcsError, createTestGuest, createTestList } from '../test-utils.ts'

const { addUser, removeImportedExclusions, removeParticipant, searchUsers } = vi.hoisted(() => ({
	addUser: vi.fn(),
	removeImportedExclusions: vi.fn(),
	removeParticipant: vi.fn(),
	searchUsers: vi.fn(),
}))
vi.mock('../api/index.ts', () => ({ addUser, removeImportedExclusions, removeParticipant, searchUsers }))
const { spawnDialog } = vi.hoisted(() => ({ spawnDialog: vi.fn() }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog }))

const list = createTestList()
const alice = createTestGuest('1', 'Alice')
const bob = { id: '2', userId: 'bob', displayName: 'Bob & Co', email: null, hasEmail: false }
const carol = createTestGuest('3', 'Carol')
const lastYear = createTestList({ id: '11', name: 'Last year' })

/**
 * Mount the section with alice, bob and carol, alice must not draw bob and carol
 *
 * @param currentList - The list shown in the section
 */
function mountSection(currentList = list) {
	return mount(ParticipantsSection, {
		attachTo: document.body,
		props: {
			list: currentList,
			lists: [currentList, lastYear],
			participants: [alice, bob, carol],
			exclusions: [{ giverId: '1', receiverId: '2' }, { giverId: '1', receiverId: '3' }],
		},
	})
}

/**
 * Open the actions menu of a participant and get its actions
 *
 * @param wrapper - The mounted section
 * @param name - The name of the participant
 */
async function openActions(wrapper: ReturnType<typeof mountSection>, name: string) {
	await wrapper.find(`button[aria-label="Actions for ${name}"]`).trigger('click')
	await flushPromises()
	// The menu is rendered after the popover opened
	vi.runOnlyPendingTimers()
	await flushPromises()
	return wrapper.findAllComponents(NcActionButton)
}

/**
 * Click an action of a participant
 *
 * @param wrapper - The mounted section
 * @param name - The name of the participant
 * @param label - The label of the action
 */
async function clickAction(wrapper: ReturnType<typeof mountSection>, name: string, label: string) {
	const actions = await openActions(wrapper, name)
	await actions.find((action) => action.text() === label)!.vm.$emit('click', new MouseEvent('click'))
	await flushPromises()
}

describe('ParticipantsSection', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		addUser.mockReset()
		removeImportedExclusions.mockReset()
		removeParticipant.mockReset()
		searchUsers.mockReset()
		spawnDialog.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('only shows the participants of archived lists', () => {
		const wrapper = mountSection(createTestList({ archivedAt: 1_766_000_000, exclusionsImported: true }))

		expect(wrapper.findComponent(NcSelectUsers).exists()).toBe(false)
		expect(wrapper.findAll('button').map((button) => button.text())).toEqual([])
		expect(wrapper.text()).toContain('Does not draw: Bob & Co, Carol')
		expect(wrapper.text()).toContain('Exclusions were imported from earlier draws')
	})

	it('shows the participants with their email address and excluded partners', () => {
		const wrapper = mountSection()

		const items = wrapper.findAllComponents(NcListItemIcon)
		expect(items.map((item) => [item.props('name'), item.props('subname')])).toEqual([
			['Alice', 'alice@example.com'],
			['Bob & Co', 'No email address, can not be notified'],
			['Carol', 'carol@example.com'],
		])
		expect(wrapper.find('[data-participant="1"]').text()).toContain('Does not draw: Bob & Co, Carol')
		expect(wrapper.find('[data-participant="3"]').text()).not.toContain('Does not draw')
	})

	it('searches users that do not participate yet', async () => {
		searchUsers.mockResolvedValue([{ id: 'bob', label: 'Bob & Co' }, { id: 'dave', label: 'Dave', subline: 'dave@example.com' }])
		const wrapper = mountSection()

		await wrapper.findComponent(NcSelectUsers).vm.$emit('search', 'd')
		await wrapper.findComponent(NcSelectUsers).vm.$emit('search', 'da')
		vi.advanceTimersByTime(300)
		await flushPromises()

		expect(searchUsers).toHaveBeenCalledTimes(1)
		expect(searchUsers).toHaveBeenCalledWith('da')
		expect(wrapper.findComponent(NcSelectUsers).props('options')).toEqual([{ id: 'dave', user: 'dave', displayName: 'Dave', subname: 'dave@example.com' }])
	})

	it('adds the selected user', async () => {
		addUser.mockResolvedValue({ id: '4', userId: 'dave', displayName: 'Dave', email: null, hasEmail: true })
		const wrapper = mountSection()

		await wrapper.findComponent(NcSelectUsers).vm.$emit('update:modelValue', { id: 'dave', user: 'dave', displayName: 'Dave' })
		await flushPromises()

		expect(addUser).toHaveBeenCalledWith(list.id, 'dave')
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('shows why a user could not be added', async () => {
		addUser.mockRejectedValue(createOcsError(400, 'Dave is already a participant.'))
		const wrapper = mountSection()

		await wrapper.findComponent(NcSelectUsers).vm.$emit('update:modelValue', { id: 'dave', user: 'dave', displayName: 'Dave' })
		await flushPromises()

		expect(wrapper.text()).toContain('Dave is already a participant.')
		expect(wrapper.emitted('changed')).toBeUndefined()
	})

	it('adds a guest', async () => {
		spawnDialog.mockResolvedValue(createTestGuest('4', 'Dave'))
		const wrapper = mountSection()

		await wrapper.findAll('button').find((button) => button.text() === 'Add guest')!.trigger('click')
		await flushPromises()

		expect(spawnDialog).toHaveBeenCalledWith(GuestDialog, { list, guest: undefined })
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('edits only guests', async () => {
		spawnDialog.mockResolvedValue(undefined)
		const wrapper = mountSection()

		expect((await openActions(wrapper, 'Bob & Co')).map((action) => action.text())).toEqual(['Partners not to draw', 'Remove'])
		await clickAction(wrapper, 'Alice', 'Edit')

		expect(spawnDialog).toHaveBeenCalledWith(GuestDialog, { list, guest: alice })
		expect(wrapper.emitted('changed')).toBeUndefined()
	})

	it('edits the excluded partners', async () => {
		const exclusions = [{ giverId: '3', receiverId: '1' }]
		spawnDialog.mockResolvedValue(exclusions)
		const wrapper = mountSection()

		await clickAction(wrapper, 'Carol', 'Partners not to draw')

		expect(spawnDialog).toHaveBeenCalledWith(ExclusionsDialog, expect.objectContaining({ participant: carol, participants: [alice, bob, carol] }))
		expect(wrapper.emitted('update:exclusions')).toEqual([[exclusions]])
	})

	it('removes a participant', async () => {
		removeParticipant.mockResolvedValue(undefined)
		const wrapper = mountSection()

		await clickAction(wrapper, 'Bob & Co', 'Remove')

		expect(removeParticipant).toHaveBeenCalledWith(list.id, '2')
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('imports exclusions', async () => {
		const updated = createTestList({ exclusionsImported: true })
		spawnDialog.mockResolvedValue(updated)
		const wrapper = mountSection()
		expect(wrapper.text()).not.toContain('Exclusions were imported')

		await clickButton('Import exclusions')

		expect(spawnDialog).toHaveBeenCalledWith(ImportExclusionsDialog, { list, lists: [list, lastYear] })
		expect(wrapper.emitted('update:list')).toEqual([[updated]])
	})

	it('removes the imported exclusions', async () => {
		const updated = createTestList()
		removeImportedExclusions.mockResolvedValue(updated)
		const wrapper = mountSection(createTestList({ exclusionsImported: true }))
		expect(wrapper.text()).toContain('Exclusions were imported from earlier draws')

		await clickButton('Remove imported exclusions')

		expect(removeImportedExclusions).toHaveBeenCalledWith(list.id)
		expect(wrapper.emitted('update:list')).toEqual([[updated]])
	})

	it('shows why the imported exclusions could not be removed', async () => {
		removeImportedExclusions.mockRejectedValue(createOcsError(404, 'Not found'))
		const wrapper = mountSection(createTestList({ exclusionsImported: true }))

		await clickButton('Remove imported exclusions')

		expect(wrapper.text()).toContain('Not found')
		expect(wrapper.emitted('update:list')).toBeUndefined()
	})
})
