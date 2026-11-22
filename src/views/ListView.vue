<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { IDraw, IExclusion, IParticipant } from '../models/types.ts'

import { mdiArchiveArrowUpOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DrawSection from '../components/DrawSection.vue'
import MailSection from '../components/MailSection.vue'
import ParticipantsSection from '../components/ParticipantsSection.vue'
import SharingSection from '../components/SharingSection.vue'
import { fetchDraws, fetchExclusions, fetchParticipants, unarchiveList } from '../api/index.ts'
import { useListsStore } from '../store/lists.ts'
import { getOcsErrorMessage, isNotFoundError } from '../utils/errors.ts'
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
const unarchiveError = ref('')

watch(() => props.listId, async (listId) => {
	loading.value = true
	unarchiveError.value = ''
	await store.ready
	if (listId !== props.listId) {
		return
	}
	if (store.loaded && !list.value) {
		router.replace({ name: 'not-found' })
		return
	}
	await load()
}, { immediate: true })

/**
 * Load the participants, exclusions and draws of the list.
 *
 * The result is ignored if another list was opened in the meantime.
 */
async function load() {
	const listId = props.listId
	failed.value = false
	try {
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
		if (listId !== props.listId) {
			return
		}
		if (isNotFoundError(error)) {
			router.replace({ name: 'not-found' })
			return
		}
		logger.error('Failed to load the list', { error })
		failed.value = true
	}
	loading.value = false
}

/**
 * Unarchive the list, so it can be changed again
 */
async function unarchive() {
	unarchiveError.value = ''
	try {
		store.setList(await unarchiveList(props.listId))
	} catch (error) {
		logger.error('Failed to unarchive the list', { error })
		unarchiveError.value = getOcsErrorMessage(error) ?? t('secret_santa', 'Could not unarchive the list.')
	}
}
</script>

<template>
	<NcNoteCard v-if="failed || (store.failed && !list)" type="error" :text="t('secret_santa', 'Could not load the list.')" />
	<NcEmptyContent v-else-if="loading || !list" :name="t('secret_santa', 'Loading list …')">
		<template #icon>
			<NcLoadingIcon />
		</template>
	</NcEmptyContent>
	<main v-else :class="$style.listView">
		<h2 :class="$style.listView__heading">
			{{ list.name }}
		</h2>
		<NcNoteCard v-if="list.archivedAt !== null" type="info">
			<p>{{ t('secret_santa', 'This list is archived and can not be changed.') }}</p>
			<NcButton :class="$style.listView__unarchive" variant="secondary" @click="unarchive">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArchiveArrowUpOutline" />
				</template>
				{{ t('secret_santa', 'Unarchive') }}
			</NcButton>
		</NcNoteCard>
		<NcNoteCard v-if="unarchiveError" type="error" :text="unarchiveError" />
		<ParticipantsSection
			v-model:exclusions="exclusions"
			:list="list"
			:lists="store.lists"
			:participants="participants"
			@changed="load"
			@update:list="store.setList" />
		<MailSection
			:list="list"
			:participants="participants"
			@update:list="store.setList" />
		<DrawSection
			v-model:draws="draws"
			:list="list"
			:participants="participants" />
		<SharingSection :list="list" />
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

.listView__unarchive {
	margin-block-start: var(--default-grid-baseline);
}
</style>
