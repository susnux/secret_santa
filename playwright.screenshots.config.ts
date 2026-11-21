/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig } from '@playwright/test'
import config from './playwright.config.ts'

/**
 * Generates the screenshots in docs/screenshots, run with `npm run screenshots`
 */
export default defineConfig({
	...config,
	testDir: './tests/playwright/screenshots',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: 'list',
	timeout: 5 * 60 * 1000,
	projects: config.projects!.map((project) => ({
		...project,
		use: {
			...project.use,
			viewport: { width: 1280, height: 800 },
			locale: 'en-US',
		},
	})),
})
