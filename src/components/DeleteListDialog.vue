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
import { deleteList } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { PLAIN_TEXT } from '../utils/l10n.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	list: ISecretSantaList
}>()

const emit = defineEmits<{
	close: [deleted: boolean]
}>()

const deleted = ref(false)
const error = ref('')
const loading = ref(false)

const buttons = computed<InstanceType<typeof NcDialog>['buttons']>(() => [
	{
		variant: 'tertiary',
		label: t('secret_santa', 'Cancel'),
	},
	{
		variant: 'error',
		label: t('secret_santa', 'Delete list'),
		disabled: loading.value,
		callback: onDelete,
	},
])

/**
 * Delete the list, the dialog stays open on failure
 */
async function onDelete() {
	error.value = ''
	loading.value = true
	try {
		await deleteList(props.list.id)
		deleted.value = true
	} catch (e) {
		logger.error('Failed to delete the list', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not delete the list.')
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
		emit('close', deleted.value)
	}
}
</script>

<template>
	<NcDialog
		:buttons="buttons"
		:name="t('secret_santa', 'Delete list')"
		:message="t('secret_santa', 'Delete the list {name} with all participants and draws? This can not be undone.', { name: list.name }, PLAIN_TEXT)"
		@update:open="onUpdateOpen">
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>
