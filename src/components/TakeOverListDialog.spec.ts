/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import TakeOverListDialog from './TakeOverListDialog.vue'
import { clickButton, createOcsError, createTestList } from '../test-utils.ts'

const { takeOverList } = vi.hoisted(() => ({ takeOverList: vi.fn() }))
vi.mock('../api/index.ts', () => ({ takeOverList }))

const list = createTestList({ owner: null, ownerDisplayName: null })

describe('TakeOverListDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		takeOverList.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('takes over the list', async () => {
		const takenOver = { ...list, owner: 'admin', ownerDisplayName: 'Olivia Owner' }
		takeOverList.mockResolvedValue(takenOver)
		const wrapper = mount(TakeOverListDialog, { attachTo: document.body, props: { list } })
		await flushPromises()

		expect(document.body.textContent).toContain(`Take over the list ${list.name}?`)
		await clickButton('Take over list')

		expect(takeOverList).toHaveBeenCalledWith(list.id)
		expect(wrapper.emitted('close')).toEqual([[takenOver]])
	})

	it('shows the error of the server and stays open', async () => {
		takeOverList.mockRejectedValue(createOcsError(403, 'The list already has an owner.'))
		const wrapper = mount(TakeOverListDialog, { attachTo: document.body, props: { list } })
		await flushPromises()

		await clickButton('Take over list')

		expect(document.body.textContent).toContain('The list already has an owner.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
