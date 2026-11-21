/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import ImportExclusionsDialog from './ImportExclusionsDialog.vue'
import { clickButton, createOcsError, createTestList } from '../test-utils.ts'

const { importExclusions } = vi.hoisted(() => ({ importExclusions: vi.fn() }))
vi.mock('../api/index.ts', () => ({ importExclusions }))

const list = createTestList()
const lastYear = createTestList({ id: '11', name: 'Last year & more' })

/**
 * Mount the dialog for importing into the list
 */
async function mountDialog() {
	const wrapper = mount(ImportExclusionsDialog, {
		attachTo: document.body,
		props: { list, lists: [list, lastYear] },
	})
	await flushPromises()
	return wrapper
}

/**
 * Get the import button of the dialog
 */
function getImportButton() {
	return [...document.querySelectorAll('button')].find((element) => element.textContent?.trim() === 'Import')!
}

describe('ImportExclusionsDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		importExclusions.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('shows the lists of the user including the list itself', async () => {
		const wrapper = await mountDialog()

		const options = wrapper.findAllComponents(NcCheckboxRadioSwitch)
		expect(options.map((option) => option.text())).toEqual(['Christmas (this list)', 'Last year & more'])
		expect(getImportButton().disabled).toBe(true)
	})

	it('imports the partners of the selected list', async () => {
		const updated = createTestList({ exclusionsImported: true })
		importExclusions.mockResolvedValue(updated)
		const wrapper = await mountDialog()

		await wrapper.findAllComponents(NcCheckboxRadioSwitch)[1]!.vm.$emit('update:modelValue', lastYear.id)
		await clickButton('Import')

		expect(importExclusions).toHaveBeenCalledWith(list.id, lastYear.id)
		expect(wrapper.emitted('close')).toEqual([[updated]])
	})

	it('shows the error of the server and stays open', async () => {
		importExclusions.mockRejectedValue(createOcsError(400, 'The partners of Last year have not been drawn yet.'))
		const wrapper = await mountDialog()

		await wrapper.findAllComponents(NcCheckboxRadioSwitch)[1]!.vm.$emit('update:modelValue', lastYear.id)
		await clickButton('Import')

		expect(document.body.textContent).toContain('The partners of Last year have not been drawn yet.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
