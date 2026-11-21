<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ISecretSantaList } from '../models/types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { archiveList } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { PLAIN_TEXT } from '../utils/l10n.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	list: ISecretSantaList
}>()

const emit = defineEmits<{
	close: [list?: ISecretSantaList]
}>()

const archived = ref<ISecretSantaList>()
const error = ref('')
const loading = ref(false)

const buttons = computed<InstanceType<typeof NcDialog>['buttons']>(() => [
	{
		variant: 'tertiary',
		label: t('secret_santa', 'Cancel'),
	},
	{
		variant: 'primary',
		label: t('secret_santa', 'Archive list'),
		disabled: loading.value,
		callback: onArchive,
	},
])

/**
 * Archive the list, the dialog stays open on failure
 */
async function onArchive() {
	error.value = ''
	loading.value = true
	try {
		archived.value = await archiveList(props.list.id)
	} catch (e) {
		logger.error('Failed to archive the list', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not archive the list.')
		return false
	} finally {
		loading.value = false
	}
}

/**
 * @param open - The new open state of the dialog
 */
function onUpdateOpen(open: boolean) {
	if (!open) {
		emit('close', archived.value)
	}
}
</script>

<template>
	<NcDialog
		:buttons="buttons"
		:name="t('secret_santa', 'Archive list')"
		:message="t('secret_santa', 'Archive the list {name}? Archived lists can not be changed until they are unarchived, but their partners can still be imported as exclusions.', { name: list.name }, PLAIN_TEXT)"
		@update:open="onUpdateOpen">
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>
