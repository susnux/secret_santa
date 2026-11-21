<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { IParticipant, ISecretSantaList } from '../models/types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { addGuest, updateGuest } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	list: ISecretSantaList
	/** The guest to edit, a new guest is added if not set */
	guest?: IParticipant
}>()

const emit = defineEmits<{
	close: [guest?: IParticipant]
}>()

const displayName = ref(props.guest?.displayName ?? '')
const email = ref(props.guest?.email ?? '')
const saved = ref<IParticipant>()
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
		label: props.guest ? t('secret_santa', 'Save') : t('secret_santa', 'Add guest'),
		disabled: loading.value,
		callback: save,
	},
])

/**
 * Add or update the guest, the dialog stays open on failure
 */
async function save() {
	error.value = ''
	loading.value = true
	try {
		saved.value = props.guest
			? await updateGuest(props.list.id, props.guest.id, displayName.value, email.value)
			: await addGuest(props.list.id, displayName.value, email.value)
	} catch (e) {
		logger.error('Failed to save the guest', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not save the guest.')
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
		:name="guest ? t('secret_santa', 'Edit guest') : t('secret_santa', 'Add guest')"
		@update:open="onUpdateOpen">
		<NcTextField
			v-model="displayName"
			required
			:maxlength="255"
			:label="t('secret_santa', 'Name')" />
		<NcTextField
			v-model="email"
			required
			type="email"
			:maxlength="255"
			:label="t('secret_santa', 'Email address')" />
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>
