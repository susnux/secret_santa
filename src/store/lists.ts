/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ISecretSantaList } from '../models/types.ts'

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchLists } from '../api/index.ts'
import logger from '../utils/logger.ts'

export const useListsStore = defineStore('secret_santa-lists', () => {
	const lists = ref<ISecretSantaList[]>([])
	const loaded = ref(false)
	const failed = ref(false)

	/**
	 * Load the lists of the current user
	 */
	async function load() {
		failed.value = false
		try {
			lists.value = await fetchLists()
			loaded.value = true
		} catch (error) {
			logger.error('Failed to load the lists', { error })
			failed.value = true
		}
	}

	/**
	 * Add a new list or replace a list with its updated state
	 *
	 * @param list - The new or updated list
	 */
	function setList(list: ISecretSantaList) {
		const index = lists.value.findIndex(({ id }) => id === list.id)
		if (index === -1) {
			lists.value.push(list)
		} else {
			lists.value.splice(index, 1, list)
		}
	}

	/**
	 * Remove a deleted list
	 *
	 * @param listId - The id of the deleted list
	 */
	function removeList(listId: string) {
		lists.value = lists.value.filter(({ id }) => id !== listId)
	}

	/** Resolved when the lists were loaded for the first time, even if loading failed */
	const ready = load()

	return {
		lists,
		loaded,
		failed,
		ready,
		load,
		setList,
		removeList,
	}
})
