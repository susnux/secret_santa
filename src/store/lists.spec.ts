/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createTestList } from '../test-utils.ts'
import { useListsStore } from './lists.ts'

const { fetchLists } = vi.hoisted(() => ({ fetchLists: vi.fn() }))
vi.mock('../api/index.ts', () => ({ fetchLists }))

describe('lists store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		fetchLists.mockReset()
	})

	it('loads the lists', async () => {
		const list = createTestList()
		fetchLists.mockResolvedValue([list])

		const store = useListsStore()
		await flushPromises()

		expect(store.lists).toEqual([list])
		expect(store.loaded).toBe(true)
		expect(store.failed).toBe(false)
	})

	it('reports a failed load', async () => {
		fetchLists.mockRejectedValue(new Error('Network error'))

		const store = useListsStore()
		await flushPromises()

		expect(store.loaded).toBe(false)
		expect(store.failed).toBe(true)
	})

	it('adds, replaces and removes lists', async () => {
		fetchLists.mockResolvedValue([createTestList({ id: '1' })])
		const store = useListsStore()
		await flushPromises()

		store.setList(createTestList({ id: '2', name: 'New' }))
		store.setList(createTestList({ id: '1', name: 'Renamed' }))
		expect(store.lists.map(({ id, name }) => [id, name])).toEqual([['1', 'Renamed'], ['2', 'New']])

		store.removeList('1')
		expect(store.lists.map(({ id }) => id)).toEqual(['2'])
	})
})
