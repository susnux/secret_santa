<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import AppNavigation from './views/AppNavigation.vue'
</script>

<template>
	<NcContent appName="secret_santa">
		<AppNavigation />
		<NcAppContent :pageHeading="t('secret_santa', 'Secret Santa')">
			<RouterView />
		</NcAppContent>
	</NcContent>
</template>
