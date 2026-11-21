<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { mdiGiftOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

const route = useRoute()

const name = computed(() => route.name === 'not-found'
	? t('secret_santa', 'List not found')
	: t('secret_santa', 'No list selected'))

const description = computed(() => route.name === 'not-found'
	? t('secret_santa', 'The list does not exist anymore. Select a list from the menu or create a new one.')
	: t('secret_santa', 'Select a list from the menu or create a new one to start a gift exchange.'))
</script>

<template>
	<NcEmptyContent :class="$style.emptyView" :name="name" :description="description">
		<template #icon>
			<NcIconSvgWrapper :path="mdiGiftOutline" />
		</template>
	</NcEmptyContent>
</template>

<style module>
.emptyView {
	height: 100%;
}
</style>
