<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { IDraw, IExclusion, IParticipant } from '../models/types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DrawSection from '../components/DrawSection.vue'
import MailSection from '../components/MailSection.vue'
import ParticipantsSection from '../components/ParticipantsSection.vue'
import { fetchDraws, fetchExclusions, fetchParticipants } from '../api/index.ts'
import { useListsStore } from '../store/lists.ts'
import { isNotFoundError } from '../utils/errors.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	listId: string
}>()

const store = useListsStore()
const router = useRouter()

const list = computed(() => store.lists.find(({ id }) => id === props.listId))
const participants = ref<IParticipant[]>([])
const exclusions = ref<IExclusion[]>([])
const draws = ref<IDraw[]>([])
const loading = ref(true)
const failed = ref(false)

watch(() => props.listId, () => {
	loading.value = true
	load()
}, { immediate: true })
watch(() => store.loaded && !list.value, (missing) => {
	if (missing) {
		router.replace({ name: 'not-found' })
	}
}, { immediate: true })

/**
 * Load the participants, exclusions and draws of the list
 */
async function load() {
	failed.value = false
	try {
		const listId = props.listId
		const [newParticipants, newExclusions, newDraws] = await Promise.all([
			fetchParticipants(listId),
			fetchExclusions(listId),
			fetchDraws(listId),
		])
		if (listId !== props.listId) {
			return
		}
		participants.value = newParticipants
		exclusions.value = newExclusions
		draws.value = newDraws
	} catch (error) {
		if (isNotFoundError(error)) {
			router.replace({ name: 'not-found' })
			return
		}
		logger.error('Failed to load the list', { error })
		failed.value = true
	} finally {
		loading.value = false
	}
}
</script>

<template>
	<NcEmptyContent v-if="loading || !list" :name="t('secret_santa', 'Loading list …')">
		<template #icon>
			<NcLoadingIcon />
		</template>
	</NcEmptyContent>
	<NcNoteCard v-else-if="failed" type="error" :text="t('secret_santa', 'Could not load the list.')" />
	<main v-else :class="$style.listView">
		<h2 :class="$style.listView__heading">
			{{ list.name }}
		</h2>
		<ParticipantsSection
			v-model:exclusions="exclusions"
			:list="list"
			:participants="participants"
			@changed="load" />
		<MailSection
			:list="list"
			:participants="participants"
			@update:list="store.setList" />
		<DrawSection
			v-model:draws="draws"
			:list="list"
			:participants="participants" />
	</main>
</template>

<style module>
.listView {
	box-sizing: border-box;
	max-width: 800px;
	margin-inline: auto;
	padding: calc(4 * var(--default-grid-baseline));
	padding-top: calc(var(--default-clickable-area) + 2 * var(--default-grid-baseline));
}

.listView__heading {
	margin-block: 0 calc(4 * var(--default-grid-baseline));
}
</style>
