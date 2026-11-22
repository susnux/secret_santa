<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { IAutocompleteResult, ISecretSantaList, IShare } from '../models/types.ts'

import { mdiClose } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItemIcon from '@nextcloud/vue/components/NcListItemIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { fetchShares, searchGroups, shareList, unshareList } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { PLAIN_TEXT } from '../utils/l10n.ts'
import { isOwnList } from '../utils/lists.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	list: ISecretSantaList
}>()

const shares = ref<IShare[]>([])
const error = ref('')

const isOwner = computed(() => isOwnList(props.list))

const description = computed(() => {
	if (isOwner.value) {
		return t('secret_santa', 'All members of the groups can change the list and draw the partners. Only you can delete and share the list.')
	}
	if (props.list.ownerDisplayName !== null) {
		return t('secret_santa', 'Shared by {owner}. All members of the groups can change the list and draw the partners. Only {owner} can delete and share the list.', { owner: props.list.ownerDisplayName }, PLAIN_TEXT)
	}
	return t('secret_santa', 'The owner of this list was deleted. All members of the groups can still change the list and draw the partners.')
})

watch(() => props.list.id, async (listId) => {
	shares.value = []
	error.value = ''
	try {
		const loaded = await fetchShares(listId)
		if (listId === props.list.id) {
			shares.value = loaded
		}
	} catch (e) {
		logger.error('Failed to load the shares', { error: e })
		error.value = t('secret_santa', 'Could not load the groups the list is shared with.')
	}
}, { immediate: true })

const groupOptions = ref<IAutocompleteResult[]>([])
const searching = ref(false)
// Re-created after a group was added to clear the selection
const groupSelectKey = ref(0)
let searchTimeout: ReturnType<typeof setTimeout> | undefined
let searchQuery = ''

/**
 * Search the groups matching the query, debounced while typing
 *
 * @param query - The search query
 */
function onSearch(query: string) {
	searchQuery = query
	clearTimeout(searchTimeout)
	if (query.trim() === '') {
		groupOptions.value = []
		return
	}
	searchTimeout = setTimeout(async () => {
		searching.value = true
		try {
			const groups = await searchGroups(query)
			if (query === searchQuery) {
				const sharedGroups = new Set(shares.value.map(({ groupId }) => groupId))
				groupOptions.value = groups.filter(({ id }) => !sharedGroups.has(id))
			}
		} catch (e) {
			logger.error('Failed to search groups', { error: e })
		} finally {
			searching.value = false
		}
	}, 300)
}

/**
 * Share the list with the selected group
 *
 * @param group - The selected group
 */
async function onSelectGroup(group: IAutocompleteResult | null) {
	if (!group) {
		return
	}
	error.value = ''
	try {
		const share = await shareList(props.list.id, group.id)
		shares.value = [...shares.value, share].sort((a, b) => a.groupId.localeCompare(b.groupId))
	} catch (e) {
		logger.error('Failed to share the list', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not share the list with {group}.', { group: group.label }, PLAIN_TEXT)
	} finally {
		groupOptions.value = []
		groupSelectKey.value++
	}
}

/**
 * Stop sharing the list with a group
 *
 * @param share - The share to remove
 */
async function unshare(share: IShare) {
	error.value = ''
	try {
		await unshareList(props.list.id, share.groupId)
		shares.value = shares.value.filter(({ groupId }) => groupId !== share.groupId)
	} catch (e) {
		logger.error('Failed to stop sharing the list', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not stop sharing the list with {group}.', { group: share.displayName }, PLAIN_TEXT)
	}
}
</script>

<template>
	<section :class="$style.sharingSection" :aria-labelledby="`secret-santa-sharing-${list.id}`">
		<h3 :id="`secret-santa-sharing-${list.id}`">
			{{ t('secret_santa', 'Sharing') }}
		</h3>
		<p :class="$style.sharingSection__description">
			{{ description }}
		</p>
		<NcSelect
			v-if="isOwner"
			:key="groupSelectKey"
			:class="$style.sharingSection__select"
			:inputLabel="t('secret_santa', 'Share with a group')"
			:placeholder="t('secret_santa', 'Search groups')"
			:loading="searching"
			:options="groupOptions"
			:filterable="false"
			label="label"
			@search="onSearch"
			@update:modelValue="onSelectGroup" />
		<NcNoteCard v-if="error" type="error" :text="error" />
		<p v-if="shares.length === 0" :class="$style.sharingSection__empty">
			{{ t('secret_santa', 'The list is not shared.') }}
		</p>
		<ul v-else :class="$style.sharingSection__list" :aria-label="t('secret_santa', 'Groups the list is shared with')">
			<li
				v-for="share in shares"
				:key="share.groupId"
				:class="$style.sharingSection__share"
				:data-group="share.groupId">
				<NcListItemIcon
					:class="$style.sharingSection__group"
					:name="share.displayName"
					isNoUser />
				<NcButton
					v-if="isOwner"
					variant="tertiary"
					:aria-label="t('secret_santa', 'Stop sharing with {group}', { group: share.displayName }, PLAIN_TEXT)"
					@click="unshare(share)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<style module>
.sharingSection {
	margin-block-end: calc(6 * var(--default-grid-baseline));
}

.sharingSection__description,
.sharingSection__empty {
	color: var(--color-text-maxcontrast);
}

.sharingSection__select {
	width: 100%;
	margin-block: calc(2 * var(--default-grid-baseline));
}

.sharingSection__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.sharingSection__share {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
	padding: var(--default-grid-baseline);
	border-radius: var(--border-radius-element);
}

.sharingSection__share:hover {
	background-color: var(--color-background-hover);
}

.sharingSection__group {
	flex: 1 1 auto;
	min-width: 0;
}
</style>
