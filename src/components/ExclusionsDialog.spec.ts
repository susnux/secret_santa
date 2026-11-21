/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import ExclusionsDialog from './ExclusionsDialog.vue'
import { clickButton, createOcsError, createTestGuest, createTestList } from '../test-utils.ts'

const { setExclusions } = vi.hoisted(() => ({ setExclusions: vi.fn() }))
vi.mock('../api/index.ts', () => ({ setExclusions }))

const list = createTestList()
const [alice, bob, carol] = [createTestGuest('1', 'Alice'), createTestGuest('2', 'Bob & Co'), createTestGuest('3', 'Carol')]

/**
 * Mount the dialog for alice, who must not draw bob
 */
async function mountDialog() {
	const wrapper = mount(ExclusionsDialog, {
		attachTo: document.body,
		props: {
			list,
			participant: alice,
			participants: [alice, bob, carol],
			exclusions: [{ giverId: '1', receiverId: '2' }, { giverId: '3', receiverId: '1' }],
		},
	})
	await flushPromises()
	return wrapper
}

describe('ExclusionsDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		setExclusions.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('shows the other participants with the excluded ones checked', async () => {
		const wrapper = await mountDialog()

		const [bobCheckbox, carolCheckbox, mutualSwitch] = wrapper.findAllComponents(NcCheckboxRadioSwitch)
		expect(bobCheckbox!.text()).toBe('Bob & Co')
		expect(bobCheckbox!.props('modelValue')).toEqual(['2'])
		expect(carolCheckbox!.text()).toBe('Carol')
		expect(mutualSwitch!.props('modelValue')).toBe(true)
		expect(document.body.textContent).toContain('Alice must not draw:')
	})

	it('saves the excluded partners in both directions by default', async () => {
		const exclusions = [{ giverId: '1', receiverId: '3' }, { giverId: '3', receiverId: '1' }]
		setExclusions.mockResolvedValue(exclusions)
		const wrapper = await mountDialog()

		await wrapper.findAllComponents(NcCheckboxRadioSwitch)[1]!.vm.$emit('update:modelValue', ['3'])
		await clickButton('Save')

		expect(setExclusions).toHaveBeenCalledWith(list.id, alice.id, ['3'], true)
		expect(wrapper.emitted('close')).toEqual([[exclusions]])
	})

	it('saves the excluded partners in one direction', async () => {
		setExclusions.mockResolvedValue([])
		const wrapper = await mountDialog()

		await wrapper.findAllComponents(NcCheckboxRadioSwitch)[2]!.vm.$emit('update:modelValue', false)
		await clickButton('Save')

		expect(setExclusions).toHaveBeenCalledWith(list.id, alice.id, ['2'], false)
	})

	it('shows the error of the server and stays open', async () => {
		setExclusions.mockRejectedValue(createOcsError(400, 'Unknown participant.'))
		const wrapper = await mountDialog()

		await clickButton('Save')

		expect(document.body.textContent).toContain('Unknown participant.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
