/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MailSection from './MailSection.vue'
import { createOcsError, createTestGuest, createTestList } from '../test-utils.ts'

const { updateList } = vi.hoisted(() => ({ updateList: vi.fn() }))
vi.mock('../api/index.ts', () => ({ updateList }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'admin', displayName: 'Olivia Owner', isAdmin: false }) }))

const list = createTestList({ mailSubject: 'Hi {name}, about {partner}', mailBody: 'Hello {name},\nyou give {partner} a gift. {other}' })

/**
 * Mount the section of the list
 *
 * @param participants - The participants of the list
 */
function mountSection(participants = [createTestGuest('1', 'Alice'), createTestGuest('2', 'Bob & Co')]) {
	return mount(MailSection, { props: { list, participants } })
}

describe('MailSection', () => {
	beforeEach(() => {
		updateList.mockReset()
	})

	it('previews the email with the first participants', () => {
		const wrapper = mountSection()

		expect(wrapper.find('[data-preview="subject"]').text()).toBe('Hi Alice, about {partner}')
		expect(wrapper.find('[data-preview="body"]').text()).toBe('Hello Alice,\nyou give Bob & Co a gift. {other}')
		expect(wrapper.text()).toContain('Olivia Owner')
	})

	it('previews the email with example names without participants', async () => {
		const wrapper = mountSection([])
		await wrapper.findComponent(NcTextArea).vm.$emit('update:modelValue', '{partner} by {name}')

		expect(wrapper.find('[data-preview="body"]').text()).toBe('Bob by Alice')
	})

	it('shows the sender name in the preview', async () => {
		const wrapper = mountSection()

		await wrapper.findAllComponents(NcTextField)[0]!.vm.$emit('update:modelValue', 'Santa')

		expect(wrapper.find('dd').text()).toBe('Santa')
	})

	it('saves the changed email', async () => {
		const updated = createTestList({ ...list, senderName: 'Santa', mailSubject: 'Psst' })
		updateList.mockResolvedValue(updated)
		const wrapper = mountSection()
		const saveButton = wrapper.findAllComponents(NcButton).at(-1)!
		expect(saveButton.props('disabled')).toBe(true)

		await wrapper.findAllComponents(NcTextField)[0]!.vm.$emit('update:modelValue', 'Santa')
		await wrapper.findAllComponents(NcTextField)[1]!.vm.$emit('update:modelValue', 'Psst')
		expect(saveButton.props('disabled')).toBe(false)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(updateList).toHaveBeenCalledWith(list.id, { senderName: 'Santa', mailSubject: 'Psst', mailBody: list.mailBody })
		expect(wrapper.emitted('update:list')).toEqual([[updated]])
	})

	it('keeps unsaved changes when the list is renamed', async () => {
		const wrapper = mountSection()
		await wrapper.findAllComponents(NcTextField)[1]!.vm.$emit('update:modelValue', 'Unsaved')

		await wrapper.setProps({ list: { ...list, name: 'Renamed' } })
		expect(wrapper.findAllComponents(NcTextField)[1]!.props('modelValue')).toBe('Unsaved')

		await wrapper.setProps({ list: createTestList({ id: '11', mailSubject: 'Other list' }) })
		expect(wrapper.findAllComponents(NcTextField)[1]!.props('modelValue')).toBe('Other list')
	})

	it('shows the error of the server', async () => {
		updateList.mockRejectedValue(createOcsError(400, 'The partner can only be mentioned in the text of the email.'))
		const wrapper = mountSection()

		await wrapper.findAllComponents(NcTextField)[1]!.vm.$emit('update:modelValue', 'For {partner}')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.text()).toContain('The partner can only be mentioned in the text of the email.')
		expect(wrapper.emitted('update:list')).toBeUndefined()
	})
})
