<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ISecretSantaList } from '../models/types.ts'

import { mdiAlertCircleOutline, mdiGiftOutline, mdiPencil, mdiPlus, mdiTrashCan } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { useRoute, useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import DeleteListDialog from '../components/DeleteListDialog.vue'
import ListDialog from '../components/ListDialog.vue'
import { useListsStore } from '../store/lists.ts'

const store = useListsStore()
const route = useRoute()
const router = useRouter()

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
				v-for="list in store.lists"
				:key="list.id"
				:name="list.name"
				:to="{ name: 'list', params: { listId: list.id } }">
				<template #icon>
					<NcIconSvgWrapper :path="mdiGiftOutline" />
				</template>
				<template #actions>
					<NcActionButton closeAfterClick @click="rename(list)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencil" />
						</template>
						{{ t('secret_santa', 'Rename') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="remove(list)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiTrashCan" />
						</template>
						{{ t('secret_santa', 'Delete') }}
					</NcActionButton>
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>
	</NcAppNavigation>
</template>
