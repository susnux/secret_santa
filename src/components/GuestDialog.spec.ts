/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import GuestDialog from './GuestDialog.vue'
import { clickButton, createOcsError, createTestGuest, createTestList } from '../test-utils.ts'

const { addGuest, updateGuest } = vi.hoisted(() => ({ addGuest: vi.fn(), updateGuest: vi.fn() }))
vi.mock('../api/index.ts', () => ({ addGuest, updateGuest }))

const list = createTestList()

/**
 * Fill in the name and email address of the dialog
 *
 * @param wrapper - The mounted dialog
 * @param name - The name of the guest
 * @param email - The email address of the guest
 */
async function fill(wrapper: ReturnType<typeof mount>, name: string, email: string) {
	const [nameField, emailField] = wrapper.findAllComponents(NcTextField)
	await nameField!.vm.$emit('update:modelValue', name)
	await emailField!.vm.$emit('update:modelValue', email)
}

describe('GuestDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		addGuest.mockReset()
		updateGuest.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('adds a guest', async () => {
		const guest = createTestGuest('1', 'Carol')
		addGuest.mockResolvedValue(guest)
		const wrapper = mount(GuestDialog, { attachTo: document.body, props: { list } })
		await flushPromises()

		await fill(wrapper, 'Carol', 'carol@example.com')
		await clickButton('Add guest')

		expect(addGuest).toHaveBeenCalledWith(list.id, 'Carol', 'carol@example.com')
		expect(wrapper.emitted('close')).toEqual([[guest]])
	})

	it('edits a guest', async () => {
		const guest = createTestGuest('1', 'Carol')
		updateGuest.mockResolvedValue({ ...guest, displayName: 'Caroline' })
		const wrapper = mount(GuestDialog, { attachTo: document.body, props: { list, guest } })
		await flushPromises()
		expect(wrapper.findAllComponents(NcTextField).map((field) => field.props('modelValue'))).toEqual(['Carol', 'carol@example.com'])

		await fill(wrapper, 'Caroline', 'carol@example.com')
		await clickButton('Save')

		expect(updateGuest).toHaveBeenCalledWith(list.id, guest.id, 'Caroline', 'carol@example.com')
		expect(wrapper.emitted('close')).toEqual([[{ ...guest, displayName: 'Caroline' }]])
	})

	it('shows the error of the server and stays open', async () => {
		addGuest.mockRejectedValue(createOcsError(400, 'The email address is not valid.'))
		const wrapper = mount(GuestDialog, { attachTo: document.body, props: { list } })
		await flushPromises()

		await fill(wrapper, 'Carol', 'carol')
		await clickButton('Add guest')

		expect(document.body.textContent).toContain('The email address is not valid.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
