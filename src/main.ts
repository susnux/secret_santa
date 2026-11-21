/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import SecretSantaApp from './SecretSantaApp.vue'
import { router } from './router.ts'
import { pinia } from './store/index.ts'

createApp(SecretSantaApp)
	.use(pinia)
	.use(router)
	.mount('#content')
