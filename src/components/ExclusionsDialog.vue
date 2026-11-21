<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { IExclusion, IParticipant, ISecretSantaList } from '../models/types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { setExclusions } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { PLAIN_TEXT } from '../utils/l10n.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	list: ISecretSantaList
	/** The participant whose excluded partners are edited */
	participant: IParticipant
	participants: IParticipant[]
	exclusions: IExclusion[]
}>()

const emit = defineEmits<{
	close: [exclusions?: IExclusion[]]
}>()

const others = computed(() => props.participants.filter(({ id }) => id !== props.participant.id))
const receiverIds = ref(props.exclusions
	.filter(({ giverId }) => giverId === props.participant.id)
	.map(({ receiverId }) => receiverId))
const mutual = ref(true)

const saved = ref<IExclusion[]>()
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
		label: t('secret_santa', 'Save'),
		disabled: loading.value,
		callback: save,
	},
])

/**
 * Save the excluded partners, the dialog stays open on failure
 */
async function save() {
	error.value = ''
	loading.value = true
	try {
		saved.value = await setExclusions(props.list.id, props.participant.id, receiverIds.value, mutual.value)
	} catch (e) {
		logger.error('Failed to save the exclusions', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not save the excluded partners.')
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
		:name="t('secret_santa', 'Excluded partners')"
		@update:open="onUpdateOpen">
		<p v-if="others.length === 0">
			{{ t('secret_santa', 'Add more participants first.') }}
		</p>
		<fieldset v-else :class="$style.exclusionsDialog__fieldset">
			<legend :class="$style.exclusionsDialog__legend">
				{{ t('secret_santa', '{name} must not draw:', { name: participant.displayName }, PLAIN_TEXT) }}
			</legend>
			<NcCheckboxRadioSwitch
				v-for="other in others"
				:key="other.id"
				v-model="receiverIds"
				:value="other.id">
				{{ other.displayName }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<NcCheckboxRadioSwitch v-model="mutual" type="switch">
			{{ t('secret_santa', 'Apply the changes in both directions') }}
		</NcCheckboxRadioSwitch>
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>

<style module>
.exclusionsDialog__fieldset {
	margin-block-end: calc(2 * var(--default-grid-baseline));
}

.exclusionsDialog__legend {
	font-weight: bold;
}
</style>
