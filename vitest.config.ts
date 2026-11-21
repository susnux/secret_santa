/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: CC0-1.0
 */

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'happy-dom',
		include: ['src/**/*.spec.ts'],
		setupFiles: ['src/test-setup.ts'],
		server: {
			deps: {
				// Needed to process the CSS imports of the components
				inline: [/@nextcloud\/vue/],
			},
		},
	},
})
