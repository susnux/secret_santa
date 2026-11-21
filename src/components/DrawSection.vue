<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { IDraw, IParticipant, ISecretSantaList } from '../models/types.ts'

import { mdiAlertCircleOutline, mdiCheckCircleOutline, mdiEmailFastOutline, mdiShuffleVariant } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { createDraw, notifyParticipant } from '../api/index.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { PLAIN_TEXT } from '../utils/l10n.ts'
import logger from '../utils/logger.ts'

const draws = defineModel<IDraw[]>('draws', { required: true })

const props = defineProps<{
	list: ISecretSantaList
	participants: IParticipant[]
}>()

/** Minimum number of participants of a draw, see `DrawService::MIN_PARTICIPANTS` */
const MIN_PARTICIPANTS = 3

const latestDraw = computed(() => draws.value[0])
const previousDraws = computed(() => draws.value.slice(1))

const problems = computed(() => {
	const problems: string[] = []
	if (props.participants.length < MIN_PARTICIPANTS) {
		problems.push(t('secret_santa', 'At least {count} participants are needed to draw the partners.', { count: MIN_PARTICIPANTS }))
	}
	for (const participant of props.participants.filter(({ hasEmail }) => !hasEmail)) {
		problems.push(t('secret_santa', '{name} has no email address and can not be notified.', { name: participant.displayName }, PLAIN_TEXT))
	}
	return problems
})

const confirmOpen = ref(false)
const drawing = ref(false)
const error = ref('')

const confirmButtons = computed<InstanceType<typeof NcDialog>['buttons']>(() => [
	{
		variant: 'tertiary',
		label: t('secret_santa', 'Cancel'),
	},
	{
		variant: 'primary',
		label: t('secret_santa', 'Draw and send emails'),
		callback: draw,
	},
])

/**
 * Draw the partners and send the emails
 */
async function draw() {
	error.value = ''
	drawing.value = true
	try {
		draws.value = [await createDraw(props.list.id), ...draws.value]
	} catch (e) {
		logger.error('Failed to draw the partners', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not draw the partners.')
	} finally {
		drawing.value = false
	}
}

const notifying = ref<string>()

/**
 * Send the email of the latest draw to a participant again
 *
 * @param participantId - The id of the participant
 */
async function notify(participantId: string) {
	if (!latestDraw.value) {
		return
	}
	error.value = ''
	notifying.value = participantId
	try {
		const updated = await notifyParticipant(props.list.id, latestDraw.value.id, participantId)
		draws.value = draws.value.map((draw) => draw.id === updated.id ? updated : draw)
	} catch (e) {
		logger.error('Failed to send the email again', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('secret_santa', 'Could not send the email.')
	} finally {
		notifying.value = undefined
	}
}

/**
 * Get the name of a participant
 *
 * @param participantId - The id of the participant
 */
function getName(participantId: string): string {
	return props.participants.find(({ id }) => id === participantId)?.displayName ?? participantId
}
</script>

<template>
	<section :class="$style.drawSection" :aria-labelledby="`secret-santa-draw-${list.id}`">
		<h3 :id="`secret-santa-draw-${list.id}`">
			{{ t('secret_santa', 'Draw') }}
		</h3>
		<p :class="$style.drawSection__description">
			{{ t('secret_santa', 'Every participant gets an email with the partner they give a gift to. Nobody draws themselves, a partner they must not draw or their partner of the previous draw. The partners are only sent by email, you will not see them here.') }}
		</p>
		<NcNoteCard
			v-for="problem in problems"
			:key="problem"
			type="warning"
			:text="problem" />
		<NcNoteCard v-if="error" type="error" :text="error" />
		<NcButton
			variant="primary"
			:disabled="problems.length > 0 || drawing"
			@click="confirmOpen = true">
			<template #icon>
				<NcLoadingIcon v-if="drawing" />
				<NcIconSvgWrapper v-else :path="mdiShuffleVariant" />
			</template>
			{{ t('secret_santa', 'Draw partners and send emails') }}
		</NcButton>
		<NcDialog
			v-model:open="confirmOpen"
			:buttons="confirmButtons"
			:name="t('secret_santa', 'Draw the partners?')"
			:message="latestDraw
				? t('secret_santa', 'All {count} participants get an email with a new partner. The emails of the previous draw are no longer valid.', { count: participants.length })
				: t('secret_santa', 'All {count} participants get an email with their partner.', { count: participants.length })" />

		<template v-if="latestDraw">
			<NcNoteCard
				v-if="latestDraw.outdated"
				type="warning"
				:text="t('secret_santa', 'Participants of the latest draw were removed, so others have no partner anymore. Draw the partners again.')" />
			<h4 :class="$style.drawSection__heading">
				{{ t('secret_santa', 'Latest draw') }}
				<NcDateTime :class="$style.drawSection__date" :timestamp="latestDraw.createdAt * 1000" />
			</h4>
			<ul :class="$style.drawSection__notifications">
				<li
					v-for="notification in latestDraw.notifications"
					:key="notification.participantId"
					:class="$style.drawSection__notification"
					:data-participant="notification.participantId">
					<NcIconSvgWrapper
						:class="notification.notifiedAt ? $style.drawSection__success : $style.drawSection__failure"
						:path="notification.notifiedAt ? mdiCheckCircleOutline : mdiAlertCircleOutline" />
					<span :class="$style.drawSection__name">{{ getName(notification.participantId) }}</span>
					<span v-if="notification.notifiedAt" :class="$style.drawSection__status">
						{{ t('secret_santa', 'Email sent') }}
						<NcDateTime :timestamp="notification.notifiedAt * 1000" />
					</span>
					<span v-else :class="$style.drawSection__status">
						{{ t('secret_santa', 'Email not sent') }}
					</span>
					<NcButton
						:aria-label="t('secret_santa', 'Send the email to {name} again', { name: getName(notification.participantId) }, PLAIN_TEXT)"
						:disabled="notifying !== undefined || latestDraw.outdated"
						:variant="notification.notifiedAt ? 'tertiary' : 'secondary'"
						@click="notify(notification.participantId)">
						<template #icon>
							<NcLoadingIcon v-if="notifying === notification.participantId" />
							<NcIconSvgWrapper v-else :path="mdiEmailFastOutline" />
						</template>
						{{ t('secret_santa', 'Send again') }}
					</NcButton>
				</li>
			</ul>
		</template>

		<template v-if="previousDraws.length > 0">
			<h4 :class="$style.drawSection__heading">
				{{ t('secret_santa', 'Previous draws') }}
			</h4>
			<ul :class="$style.drawSection__previous">
				<li v-for="previous in previousDraws" :key="previous.id">
					<NcDateTime :timestamp="previous.createdAt * 1000" :relativeTime="false" />
				</li>
			</ul>
		</template>
	</section>
</template>

<style module>
.drawSection {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline));
}

.drawSection > :global(.notecard) {
	align-self: stretch;
}

.drawSection__description {
	color: var(--color-text-maxcontrast);
}

.drawSection__heading {
	display: flex;
	align-items: baseline;
	gap: calc(2 * var(--default-grid-baseline));
	margin-block: calc(4 * var(--default-grid-baseline)) 0;
}

.drawSection__date {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.drawSection__notifications {
	align-self: stretch;
}

.drawSection__notification {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
	min-height: var(--default-clickable-area);
}

.drawSection__name {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
}

.drawSection__status {
	color: var(--color-text-maxcontrast);
}

.drawSection__success {
	color: var(--color-success-text, var(--color-success));
}

.drawSection__failure {
	color: var(--color-warning-text, var(--color-warning));
}

.drawSection__previous {
	list-style: disc;
	padding-inline-start: calc(5 * var(--default-grid-baseline));
}
</style>
