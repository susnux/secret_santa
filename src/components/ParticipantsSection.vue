<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { NcSelectUsersModel } from '@nextcloud/vue/components/NcSelectUsers'
import type { IExclusion, IParticipant, ISecretSantaList } from '../models/types.ts'

import { mdiAccountMultipleRemoveOutline, mdiAccountPlusOutline, mdiHistory, mdiPencil, mdiTrashCan } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { computed, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItemIcon from '@nextcloud/vue/components/NcListItemIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import ExclusionsDialog from './ExclusionsDialog.vue'
import GuestDialog from './GuestDialog.vue'
import ImportExclusionsDialog from './ImportExclusionsDialog.vue'
import { addUser, removeImportedExclusions, removeParticipant, searchUsers } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { PLAIN_TEXT } from '../utils/l10n.ts'
import logger from '../utils/logger.ts'

const exclusions = defineModel<IExclusion[]>('exclusions', { required: true })

const props = defineProps<{
	list: ISecretSantaList
	/** All lists of the user, to import exclusions from */
	lists: ISecretSantaList[]
	participants: IParticipant[]
}>()

const emit = defineEmits<{
	/** The participants changed, which can also change the exclusions and draws */
	changed: []
	/** Exclusions were imported or removed */
	'update:list': [list: ISecretSantaList]
}>()

const error = ref('')

/**
 * Get the participants the given participant must not draw
 *
 * @param participant - The participant
 */
function getExcludedNames(participant: IParticipant): string {
	return exclusions.value
		.filter(({ giverId }) => giverId === participant.id)
		.map(({ receiverId }) => props.participants.find(({ id }) => id === receiverId)?.displayName)
		.filter((name) => name !== undefined)
		.join(', ')
}

/**
 * Get the second line shown for a participant
 *
 * @param participant - The participant
 */
function getSubname(participant: IParticipant): string {
	if (!participant.hasEmail) {
		return t('secret_santa', 'No email address, can not be notified')
	}
	return participant.email ?? t('secret_santa', 'Nextcloud account')
}

const userOptions = ref<NcSelectUsersModel[]>([])
const searching = ref(false)
// Re-created after a user was added to clear the selection
const userSelectKey = ref(0)
let searchTimeout: ReturnType<typeof setTimeout> | undefined
let searchQuery = ''

const participatingUsers = computed(() => new Set(props.participants.map(({ userId }) => userId)))

/**
 * Search the users matching the query, debounced while typing
 *
 * @param query - The search query
 */
function onSearch(query: string) {
	searchQuery = query
	clearTimeout(searchTimeout)
	if (query.trim() === '') {
		userOptions.value = []
		return
	}
	searchTimeout = setTimeout(async () => {
		searching.value = true
		try {
			const users = await searchUsers(query)
			if (query === searchQuery) {
				userOptions.value = users
					.filter(({ id }) => !participatingUsers.value.has(id))
					.map(({ id, label, subline }) => ({ id, user: id, displayName: label, subname: subline }))
			}
		} catch (e) {
			logger.error('Failed to search users', { error: e })
		} finally {
			searching.value = false
		}
	}, 300)
}

/**
 * Add the selected user to the list
 *
 * @param selected - The selected user
 */
async function onSelectUser(selected: NcSelectUsersModel | NcSelectUsersModel[]) {
	const user = Array.isArray(selected) ? selected[0] : selected
	if (!user) {
		return
	}
	error.value = ''
	try {
		await addUser(props.list.id, user.id)
		emit('changed')
	} catch (e) {
		logger.error('Failed to add the user', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not add {name}.', { name: user.displayName }, PLAIN_TEXT)
	} finally {
		userOptions.value = []
		userSelectKey.value++
	}
}

/**
 * Show the dialog to add or edit a guest
 *
 * @param guest - The guest to edit, a new guest is added if not set
 */
async function editGuest(guest?: IParticipant) {
	if (await spawnDialog(GuestDialog, { list: props.list, guest })) {
		emit('changed')
	}
}

/**
 * Show the dialog to edit the partners a participant must not draw
 *
 * @param participant - The participant
 */
async function editExclusions(participant: IParticipant) {
	const updated = await spawnDialog(ExclusionsDialog, {
		list: props.list,
		participant,
		participants: props.participants,
		exclusions: exclusions.value,
	})
	if (updated) {
		exclusions.value = updated
	}
}

/**
 * Show the dialog to import the partners of a draw as exclusions
 */
async function importDraw() {
	const updated = await spawnDialog(ImportExclusionsDialog, { list: props.list, lists: props.lists })
	if (updated) {
		emit('update:list', updated)
	}
}

/**
 * Remove the exclusions imported from draws
 */
async function removeImported() {
	error.value = ''
	try {
		emit('update:list', await removeImportedExclusions(props.list.id))
	} catch (e) {
		logger.error('Failed to remove the imported exclusions', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not remove the imported exclusions.')
	}
}

/**
 * Remove a participant from the list
 *
 * @param participant - The participant to remove
 */
async function remove(participant: IParticipant) {
	error.value = ''
	try {
		await removeParticipant(props.list.id, participant.id)
		emit('changed')
	} catch (e) {
		logger.error('Failed to remove the participant', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not remove {name}.', { name: participant.displayName }, PLAIN_TEXT)
	}
}
</script>

<template>
	<section :class="$style.participantsSection" :aria-labelledby="`secret-santa-participants-${list.id}`">
		<h3 :id="`secret-santa-participants-${list.id}`">
			{{ t('secret_santa', 'Participants') }}
		</h3>
		<div :class="$style.participantsSection__add">
			<NcSelectUsers
				:key="userSelectKey"
				:class="$style.participantsSection__select"
				:inputLabel="t('secret_santa', 'Add a Nextcloud account')"
				:placeholder="t('secret_santa', 'Search accounts')"
				:loading="searching"
				:options="userOptions"
				@search="onSearch"
				@update:modelValue="onSelectUser" />
			<NcButton @click="editGuest()">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAccountPlusOutline" />
				</template>
				{{ t('secret_santa', 'Add guest') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error" :text="error" />
		<p v-if="participants.length === 0" :class="$style.participantsSection__empty">
			{{ t('secret_santa', 'No participants yet. Add Nextcloud accounts or guests with their email address.') }}
		</p>
		<ul v-else :class="$style.participantsSection__list">
			<li
				v-for="participant in participants"
				:key="participant.id"
				:class="$style.participantsSection__participant"
				:data-participant="participant.id">
				<div :class="$style.participantsSection__details">
					<NcListItemIcon
						:name="participant.displayName"
						:subname="getSubname(participant)"
						:user="participant.userId ?? undefined"
						:isNoUser="participant.userId === null"
						:class="{ [$style.participantsSection__noEmail]: !participant.hasEmail }" />
					<p v-if="getExcludedNames(participant)" :class="$style.participantsSection__excluded">
						{{ t('secret_santa', 'Does not draw: {names}', { names: getExcludedNames(participant) }, PLAIN_TEXT) }}
					</p>
				</div>
				<NcActions :aria-label="t('secret_santa', 'Actions for {name}', { name: participant.displayName }, PLAIN_TEXT)">
					<NcActionButton closeAfterClick @click="editExclusions(participant)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiAccountMultipleRemoveOutline" />
						</template>
						{{ t('secret_santa', 'Partners not to draw') }}
					</NcActionButton>
					<NcActionButton v-if="participant.userId === null" closeAfterClick @click="editGuest(participant)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencil" />
						</template>
						{{ t('secret_santa', 'Edit') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="remove(participant)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiTrashCan" />
						</template>
						{{ t('secret_santa', 'Remove') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>
		<NcNoteCard v-if="list.exclusionsImported" type="info">
			<p>
				{{ t('secret_santa', 'Exclusions were imported from earlier draws: nobody draws the partner they drew there. They are not shown, as they would reveal who drew whom.') }}
			</p>
			<NcButton :class="$style.participantsSection__removeImported" variant="tertiary" @click="removeImported">
				{{ t('secret_santa', 'Remove imported exclusions') }}
			</NcButton>
		</NcNoteCard>
		<NcButton :class="$style.participantsSection__import" @click="importDraw">
			<template #icon>
				<NcIconSvgWrapper :path="mdiHistory" />
			</template>
			{{ t('secret_santa', 'Import exclusions') }}
		</NcButton>
	</section>
</template>

<style module>
.participantsSection {
	margin-block-end: calc(6 * var(--default-grid-baseline));
}

.participantsSection__add {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
	margin-block-end: calc(2 * var(--default-grid-baseline));
}

.participantsSection__select {
	flex: 1 1 300px;
}

.participantsSection__empty {
	color: var(--color-text-maxcontrast);
}

.participantsSection__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.participantsSection__participant {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
	padding: var(--default-grid-baseline);
	border-radius: var(--border-radius-element);
}

.participantsSection__participant:hover {
	background-color: var(--color-background-hover);
}

.participantsSection__details {
	flex: 1 1 auto;
	min-width: 0;
}

.participantsSection__noEmail :global(.option__lineone ~ *) {
	color: var(--color-text-error, var(--color-error-text));
}

.participantsSection__import {
	margin-block-start: calc(2 * var(--default-grid-baseline));
}

.participantsSection__removeImported {
	margin-block-start: var(--default-grid-baseline);
}

.participantsSection__excluded {
	margin-inline-start: calc(var(--default-clickable-area) + 2 * var(--default-grid-baseline));
	color: var(--color-text-maxcontrast);
}
</style>
