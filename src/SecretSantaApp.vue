<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { mdiGiftOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
</script>

<template>
	<NcContent appName="secret_santa">
		<NcAppNavigation :aria-label="t('secret_santa', 'Secret Santa lists')" />
		<NcAppContent :pageHeading="t('secret_santa', 'Secret Santa')">
			<NcEmptyContent
				:name="t('secret_santa', 'No list selected')"
				:description="t('secret_santa', 'Create a new list to start a gift exchange.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiGiftOutline" />
				</template>
			</NcEmptyContent>
		</NcAppContent>
	</NcContent>
</template>
