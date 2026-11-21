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
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { createList, updateList } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	/** The list to rename, a new list is created if not set */
	list?: ISecretSantaList
}>()

const emit = defineEmits<{
	close: [list?: ISecretSantaList]
}>()

const name = ref(props.list?.name ?? '')
const saved = ref<ISecretSantaList>()
const error = ref('')
const loading = ref(false)

const buttons = computed<InstanceType<typeof NcDialog>['buttons']>(() => [
	{
		variant: 'tertiary',
		label: t('secret_santa', 'Cancel'),
	},
	{
		type: 'submit',
		variant: 'primary',
		label: props.list ? t('secret_santa', 'Rename') : t('secret_santa', 'Create list'),
		disabled: loading.value,
		callback: save,
	},
])

/**
 * Create or rename the list, the dialog stays open on failure
 */
async function save() {
	error.value = ''
	loading.value = true
	try {
		saved.value = props.list
			? await updateList(props.list.id, { name: name.value })
			: await createList(name.value)
	} catch (e) {
		logger.error('Failed to save the list', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not save the list.')
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
		emit('close', saved.value)
	}
}
</script>

<template>
	<NcDialog
		isForm
		:buttons="buttons"
		:name="list ? t('secret_santa', 'Rename list') : t('secret_santa', 'New list')"
		@update:open="onUpdateOpen">
		<NcTextField
			v-model="name"
			required
			:maxlength="255"
			:label="t('secret_santa', 'Name of the list')"
			:placeholder="t('secret_santa', 'e.g. Christmas party')" />
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>
