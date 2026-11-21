<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ISecretSantaList } from '../models/types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { importExclusions } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { PLAIN_TEXT } from '../utils/l10n.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	/** The list to import the exclusions into */
	list: ISecretSantaList
	/** The lists of the user, the partners of their latest draw can be imported */
	lists: ISecretSantaList[]
}>()

const emit = defineEmits<{
	close: [list?: ISecretSantaList]
}>()

const sourceListId = ref<string>()

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
		label: t('secret_santa', 'Import'),
		disabled: loading.value || !sourceListId.value,
		callback: save,
	},
])

/**
 * Import the partners of the selected list, the dialog stays open on failure
 */
async function save() {
	if (!sourceListId.value) {
		return false
	}
	error.value = ''
	loading.value = true
	try {
		saved.value = await importExclusions(props.list.id, sourceListId.value)
	} catch (e) {
		logger.error('Failed to import the exclusions', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not import the exclusions.')
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
		:name="t('secret_santa', 'Import exclusions')"
		@update:open="onUpdateOpen">
		<p>
			{{ t('secret_santa', 'Nobody draws the partner they drew in the latest draw of the selected list. Participants are matched by their account or email address.') }}
		</p>
		<p :class="$style.importExclusionsDialog__hint">
			{{ t('secret_santa', 'The imported exclusions are not shown, as they would reveal who drew whom. Import again after adding participants.') }}
		</p>
		<fieldset :class="$style.importExclusionsDialog__fieldset">
			<legend :class="$style.importExclusionsDialog__legend">
				{{ t('secret_santa', 'Import the partners of:') }}
			</legend>
			<NcCheckboxRadioSwitch
				v-for="source in lists"
				:key="source.id"
				v-model="sourceListId"
				type="radio"
				name="secret-santa-import-source"
				:value="source.id">
				{{ source.id === list.id ? t('secret_santa', '{name} (this list)', { name: source.name }, PLAIN_TEXT) : source.name }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>

<style module>
.importExclusionsDialog__hint {
	color: var(--color-text-maxcontrast);
}

.importExclusionsDialog__fieldset {
	margin-block: calc(2 * var(--default-grid-baseline));
}

.importExclusionsDialog__legend {
	font-weight: bold;
}
</style>
