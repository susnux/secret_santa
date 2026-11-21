/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { User } from '@nextcloud/e2e-test-server'

import { runOcc } from '@nextcloud/e2e-test-server/docker'
import { createRandomUser, login } from '@nextcloud/e2e-test-server/playwright'
import { test as baseTest } from '@playwright/test'
import { SecretSantaAppPage } from '../sections/SecretSantaAppPage.ts'
import { retryWithBackoff } from '../utils/retry.ts'

/**
 * Test with a fresh random `user`, a `page` logged in as that user
 * and the `secretSantaApp` page object.
 * The user and all of its lists are deleted in teardown.
 */
export const test = baseTest.extend<{ user: User, secretSantaApp: SecretSantaAppPage }>({
	// eslint-disable-next-line no-empty-pattern
	user: async ({}, use) => {
		const user = await retryWithBackoff('Creating a random user', () => createRandomUser())
		await use(user)
		await runOcc(['user:delete', user.userId], { failOnError: false })
	},

	page: async ({ page, user }, use) => {
		await retryWithBackoff('Logging in as random user', () => login(page.request, user))
		await use(page)
	},

	secretSantaApp: async ({ page }, use) => {
		await use(new SecretSantaAppPage(page))
	},
})

export { expect } from '@playwright/test'
