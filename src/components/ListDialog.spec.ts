/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ListDialog from './ListDialog.vue'
import { clickButton, createOcsError, createTestList } from '../test-utils.ts'

const { createList, updateList } = vi.hoisted(() => ({ createList: vi.fn(), updateList: vi.fn() }))
vi.mock('../api/index.ts', () => ({ createList, updateList }))

describe('ListDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		createList.mockReset()
		updateList.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('creates a list', async () => {
		const list = createTestList({ name: 'Party' })
		createList.mockResolvedValue(list)
		const wrapper = mount(ListDialog, { attachTo: document.body })
		await flushPromises()

		await wrapper.findComponent(NcTextField).vm.$emit('update:modelValue', 'Party')
		await clickButton('Create list')

		expect(createList).toHaveBeenCalledWith('Party')
		expect(wrapper.emitted('close')).toEqual([[list]])
	})

	it('renames a list', async () => {
		const list = createTestList({ name: 'Party' })
		const renamed = { ...list, name: 'Christmas party' }
		updateList.mockResolvedValue(renamed)
		const wrapper = mount(ListDialog, { attachTo: document.body, props: { list } })
		await flushPromises()
		expect(wrapper.findComponent(NcTextField).props('modelValue')).toBe('Party')

		await wrapper.findComponent(NcTextField).vm.$emit('update:modelValue', 'Christmas party')
		await clickButton('Rename')

		expect(updateList).toHaveBeenCalledWith(list.id, { name: 'Christmas party' })
		expect(wrapper.emitted('close')).toEqual([[renamed]])
	})

	it('shows the error of the server and stays open', async () => {
		createList.mockRejectedValue(createOcsError(400, 'The name of the list must not be empty.'))
		const wrapper = mount(ListDialog, { attachTo: document.body })
		await flushPromises()

		await wrapper.findComponent(NcTextField).vm.$emit('update:modelValue', 'Party')
		await clickButton('Create list')

		expect(document.body.textContent).toContain('The name of the list must not be empty.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
