<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { IParticipant, ISecretSantaList } from '../models/types.ts'

import { getCurrentUser } from '@nextcloud/auth'
import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { updateList } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	list: ISecretSantaList
	participants: IParticipant[]
}>()

const emit = defineEmits<{
	'update:list': [list: ISecretSantaList]
}>()

const senderName = ref('')
const mailSubject = ref('')
const mailBody = ref('')

/**
 * Show the email of the list in the form
 *
 * @param list - The list
 */
function reset(list: ISecretSantaList) {
	senderName.value = list.senderName ?? ''
	mailSubject.value = list.mailSubject
	mailBody.value = list.mailBody
}

// Only reset for another list, so renaming the list keeps unsaved changes
watch(() => props.list.id, () => reset(props.list), { immediate: true })

const changed = computed(() => senderName.value !== (props.list.senderName ?? '')
	|| mailSubject.value !== props.list.mailSubject
	|| mailBody.value !== props.list.mailBody)

// The placeholders are passed as values to keep them in the translated text
const placeholders = { name: '{name}', partner: '{partner}' }

const preview = computed(() => {
	const name = props.participants[0]?.displayName ?? t('secret_santa', 'Alice')
	const partner = props.participants[1]?.displayName ?? t('secret_santa', 'Bob')
	return {
		sender: senderName.value.trim() || getCurrentUser()?.displayName || '',
		subject: mailSubject.value.replaceAll('{name}', name),
		body: mailBody.value.replace(/\{(name|partner)\}/g, (placeholder) => placeholder === '{name}' ? name : partner),
	}
})

const saving = ref(false)
const error = ref('')
const savedMessage = ref('')

/**
 * Save the email of the list
 */
async function save() {
	error.value = ''
	savedMessage.value = ''
	saving.value = true
	try {
		const list = await updateList(props.list.id, {
			senderName: senderName.value,
			mailSubject: mailSubject.value,
			mailBody: mailBody.value,
		})
		emit('update:list', list)
		reset(list)
		savedMessage.value = t('secret_santa', 'Email saved')
	} catch (e) {
		logger.error('Failed to save the email', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not save the email.')
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<section :class="$style.mailSection" :aria-labelledby="`secret-santa-mail-${list.id}`">
		<h3 :id="`secret-santa-mail-${list.id}`">
			{{ t('secret_santa', 'Email') }}
		</h3>
		<form :class="$style.mailSection__form" @submit.prevent="save">
			<NcTextField
				v-model="senderName"
				:maxlength="255"
				:label="t('secret_santa', 'Sender name')"
				:helperText="t('secret_santa', 'Your name is used if empty.')" />
			<NcTextField
				v-model="mailSubject"
				required
				:maxlength="255"
				:label="t('secret_santa', 'Subject')"
				:helperText="t('secret_santa', 'Use {name} for the name of the participant. The partner can only be mentioned in the text, as mail servers often log subjects.', placeholders)" />
			<NcTextArea
				v-model="mailBody"
				required
				resize="vertical"
				:inputClass="$style.mailSection__textarea"
				:label="t('secret_santa', 'Text')"
				:helperText="t('secret_santa', 'Use {name} for the name of the participant and {partner} for the partner they give a gift to.', placeholders)" />
			<NcNoteCard v-if="error" type="error" :text="error" />
			<div :class="$style.mailSection__actions">
				<span v-if="savedMessage && !changed" role="status">{{ savedMessage }}</span>
				<NcButton type="submit" variant="primary" :disabled="!changed || saving">
					{{ t('secret_santa', 'Save email') }}
				</NcButton>
			</div>
		</form>
		<figure :class="$style.mailSection__preview">
			<figcaption :class="$style.mailSection__previewCaption">
				{{ t('secret_santa', 'Preview') }}
			</figcaption>
			<dl :class="$style.mailSection__previewHeaders">
				<dt>{{ t('secret_santa', 'From') }}</dt>
				<dd>{{ preview.sender }}</dd>
				<dt>{{ t('secret_santa', 'Subject') }}</dt>
				<dd data-preview="subject">
					{{ preview.subject }}
				</dd>
			</dl>
			<p :class="$style.mailSection__previewBody" data-preview="body">
				{{ preview.body }}
			</p>
		</figure>
	</section>
</template>

<style module>
.mailSection {
	margin-block-end: calc(6 * var(--default-grid-baseline));
}

.mailSection__form {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

/* More specific than the input style of NcTextArea */
.mailSection__form .mailSection__textarea {
	min-height: 12lh;
}

.mailSection__actions {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
	color: var(--color-text-maxcontrast);
}

.mailSection__preview {
	margin: calc(4 * var(--default-grid-baseline)) 0 0;
	padding: calc(3 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container, var(--border-radius-large));
	background-color: var(--color-background-dark);
}

.mailSection__previewCaption {
	font-weight: bold;
	margin-block-end: calc(2 * var(--default-grid-baseline));
}

.mailSection__previewHeaders {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	margin-block-end: calc(2 * var(--default-grid-baseline));
}

.mailSection__previewHeaders dt,
.mailSection__previewHeaders dd {
	margin: 0;
	padding: 0;
	text-align: start;
}

.mailSection__previewHeaders dt {
	color: var(--color-text-maxcontrast);
}

.mailSection__previewBody {
	white-space: pre-wrap;
}
</style>
