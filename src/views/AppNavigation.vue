<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ISecretSantaList } from '../models/types.ts'

import { mdiAccountGroupOutline, mdiAlertCircleOutline, mdiArchiveOutline, mdiGiftOutline, mdiPencil, mdiPlus, mdiTrashCan } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ArchiveListDialog from '../components/ArchiveListDialog.vue'
import DeleteListDialog from '../components/DeleteListDialog.vue'
import ListDialog from '../components/ListDialog.vue'
import { useListsStore } from '../store/lists.ts'
import { PLAIN_TEXT } from '../utils/l10n.ts'
import { isOwnList } from '../utils/lists.ts'

const store = useListsStore()
const route = useRoute()
const router = useRouter()

const archivedListOpen = computed(() => store.archivedLists.some(({ id }) => id === route.params.listId))

/**
 * Get the hint shown for lists of other users
 *
 * @param list - The list
 */
function getSharedHint(list: ISecretSantaList): string | undefined {
	if (isOwnList(list)) {
		return undefined
	}
	return list.ownerDisplayName === null
		? t('secret_santa', 'Shared with you')
		: t('secret_santa', 'Shared by {owner}', { owner: list.ownerDisplayName }, PLAIN_TEXT)
}

/**
 * Show the dialog to create a new list and open the created list
 */
async function createNew() {
	const list = await spawnDialog(ListDialog)
	if (list) {
		store.setList(list)
		router.push({ name: 'list', params: { listId: list.id } })
	}
}

/**
 * Show the dialog to rename a list
 *
 * @param list - The list to rename
 */
async function rename(list: ISecretSantaList) {
	const updated = await spawnDialog(ListDialog, { list })
	if (updated) {
		store.setList(updated)
	}
}

/**
 * Ask for confirmation, then archive the list. An open list stays open, as it can still be viewed.
 *
 * @param list - The list to archive
 */
async function archive(list: ISecretSantaList) {
	const archived = await spawnDialog(ArchiveListDialog, { list })
	if (archived) {
		store.setList(archived)
	}
}

/**
 * Ask for confirmation, then delete the list
 *
 * @param list - The list to delete
 */
async function remove(list: ISecretSantaList) {
	if (!await spawnDialog(DeleteListDialog, { list })) {
		return
	}
	if (route.params.listId === list.id) {
		await router.push({ name: 'root' })
	}
	store.removeList(list.id)
}
</script>

<template>
	<NcAppNavigation :aria-label="t('secret_santa', 'Secret Santa lists')">
		<NcAppNavigationNew :text="t('secret_santa', 'New list')" @click="createNew">
			<template #icon>
				<NcIconSvgWrapper :path="mdiPlus" />
			</template>
		</NcAppNavigationNew>
		<NcAppNavigationList :aria-label="t('secret_santa', 'Your lists')">
			<NcAppNavigationItem
				v-if="store.failed"
				:name="t('secret_santa', 'Could not load the lists')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				v-for="list in store.activeLists"
				:key="list.id"
				:name="list.name"
				:title="getSharedHint(list)"
				:to="{ name: 'list', params: { listId: list.id } }">
				<template #icon>
					<NcIconSvgWrapper :path="isOwnList(list) ? mdiGiftOutline : mdiAccountGroupOutline" />
				</template>
				<template #actions>
					<NcActionButton closeAfterClick @click="rename(list)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencil" />
						</template>
						{{ t('secret_santa', 'Rename') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="archive(list)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiArchiveOutline" />
						</template>
						{{ t('secret_santa', 'Archive') }}
					</NcActionButton>
					<NcActionButton v-if="isOwnList(list)" closeAfterClick @click="remove(list)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiTrashCan" />
						</template>
						{{ t('secret_santa', 'Delete') }}
					</NcActionButton>
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>
		<template #footer>
			<NcAppNavigationList>
				<NcAppNavigationItem
					:name="t('secret_santa', 'Archived lists')"
					:to="{ name: 'archived' }"
					:active="archivedListOpen">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArchiveOutline" />
					</template>
					<template v-if="store.archivedLists.length > 0" #counter>
						<NcCounterBubble :count="store.archivedLists.length" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>
		</template>
	</NcAppNavigation>
</template>
