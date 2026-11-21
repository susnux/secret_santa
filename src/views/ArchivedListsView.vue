<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ISecretSantaList } from '../models/types.ts'

import { mdiArchiveArrowUpOutline, mdiArchiveOutline, mdiGiftOutline, mdiTrashCan } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DeleteListDialog from '../components/DeleteListDialog.vue'
import { unarchiveList } from '../api/index.ts'
import { useListsStore } from '../store/lists.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { PLAIN_TEXT } from '../utils/l10n.ts'
import logger from '../utils/logger.ts'

const store = useListsStore()

const error = ref('')

/**
 * Unarchive a list, it is shown in the navigation again
 *
 * @param list - The list to unarchive
 */
async function unarchive(list: ISecretSantaList) {
	error.value = ''
	try {
		store.setList(await unarchiveList(list.id))
	} catch (e) {
		logger.error('Failed to unarchive the list', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not unarchive {name}.', { name: list.name }, PLAIN_TEXT)
	}
}

/**
 * Ask for confirmation, then delete the list
 *
 * @param list - The list to delete
 */
async function remove(list: ISecretSantaList) {
	if (await spawnDialog(DeleteListDialog, { list })) {
		store.removeList(list.id)
	}
}
</script>

<template>
	<NcNoteCard v-if="store.failed" type="error" :text="t('secret_santa', 'Could not load the lists.')" />
	<NcEmptyContent v-else-if="!store.loaded" :name="t('secret_santa', 'Loading lists …')">
		<template #icon>
			<NcLoadingIcon />
		</template>
	</NcEmptyContent>
	<NcEmptyContent
		v-else-if="store.archivedLists.length === 0"
		:class="$style.archivedListsView__empty"
		:name="t('secret_santa', 'No archived lists')"
		:description="t('secret_santa', 'Archive the lists you no longer need, like the gift exchange of last year, to hide them from the menu.')">
		<template #icon>
			<NcIconSvgWrapper :path="mdiArchiveOutline" />
		</template>
	</NcEmptyContent>
	<main v-else :class="$style.archivedListsView">
		<h2 :class="$style.archivedListsView__heading">
			{{ t('secret_santa', 'Archived lists') }}
		</h2>
		<p :class="$style.archivedListsView__description">
			{{ t('secret_santa', 'Archived lists can not be changed until they are unarchived. Their partners can still be imported as exclusions into other lists.') }}
		</p>
		<NcNoteCard v-if="error" type="error" :text="error" />
		<ul :aria-label="t('secret_santa', 'Archived lists')">
			<NcListItem
				v-for="list in store.archivedLists"
				:key="list.id"
				:name="list.name"
				:to="{ name: 'list', params: { listId: list.id } }"
				forceDisplayActions
				:data-list="list.id">
				<template #icon>
					<NcIconSvgWrapper :path="mdiGiftOutline" />
				</template>
				<template #details>
					<NcDateTime :timestamp="(list.archivedAt ?? 0) * 1000" />
				</template>
				<template #actions>
					<NcActionButton closeAfterClick @click="unarchive(list)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiArchiveArrowUpOutline" />
						</template>
						{{ t('secret_santa', 'Unarchive') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="remove(list)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiTrashCan" />
						</template>
						{{ t('secret_santa', 'Delete') }}
					</NcActionButton>
				</template>
			</NcListItem>
		</ul>
	</main>
</template>

<style module>
.archivedListsView {
	box-sizing: border-box;
	max-width: 800px;
	margin-inline: auto;
	padding: calc(4 * var(--default-grid-baseline));
	padding-top: calc(var(--default-clickable-area) + 2 * var(--default-grid-baseline));
}

.archivedListsView__heading {
	margin-block: 0 calc(2 * var(--default-grid-baseline));
}

.archivedListsView__description {
	margin-block-end: calc(4 * var(--default-grid-baseline));
	color: var(--color-text-maxcontrast);
}

.archivedListsView__empty {
	height: 100%;
}
</style>
