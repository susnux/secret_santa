/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/secret-santa-app.ts'

test.describe('Lists', () => {
	test.beforeEach(async ({ secretSantaApp }) => {
		await secretSantaApp.open()
	})

	test('shows the empty view without lists', async ({ secretSantaApp }) => {
		await expect(secretSantaApp.emptyContent('No list selected')).toBeVisible()
		await expect(secretSantaApp.navigation().getByRole('list', { name: 'Your lists' }).getByRole('link')).toHaveCount(0)
		await expect(secretSantaApp.archivedListsNavigationItem()).toBeVisible()
	})

	test('creates, renames and deletes a list', async ({ secretSantaApp, page }) => {
		await secretSantaApp.createList('Christmas party')

		await expect(secretSantaApp.dialog('New list')).toBeHidden()
		await expect(secretSantaApp.listNavigationItem('Christmas party')).toBeVisible()
		await expect(page).toHaveURL(/\/apps\/secret_santa\/\d+$/)
		await expect(secretSantaApp.section('Participants').getByText('No participants yet.')).toBeVisible()

		// the list is stored and its URL is served by the server
		await page.reload()
		await expect(secretSantaApp.listHeading()).toHaveText('Christmas party')

		await secretSantaApp.clickListAction('Christmas party', 'Rename')
		await secretSantaApp.dialog('Rename list').getByRole('textbox', { name: 'Name of the list' }).fill('Office party')
		await secretSantaApp.dialog('Rename list').getByRole('button', { name: 'Rename' }).click()
		await expect(secretSantaApp.listHeading()).toHaveText('Office party')
		await expect(secretSantaApp.listNavigationItem('Office party')).toBeVisible()

		await secretSantaApp.clickListAction('Office party', 'Delete')
		await expect(secretSantaApp.dialog('Delete list')).toContainText('Delete the list Office party')
		await secretSantaApp.dialog('Delete list').getByRole('button', { name: 'Delete list' }).click()
		await expect(secretSantaApp.listNavigationItem('Office party')).toHaveCount(0)
		await expect(page).toHaveURL(/\/apps\/secret_santa\/?$/)
		await expect(secretSantaApp.emptyContent('No list selected')).toBeVisible()
	})

	test('keeps the dialog open when creating the list fails', async ({ secretSantaApp, page }) => {
		await page.route('**/apps/secret_santa/api/v1/lists', (route) => route.request().method() === 'POST'
			? route.fulfill({
					status: 400,
					contentType: 'application/json',
					body: JSON.stringify({ ocs: { meta: { status: 'failure', statuscode: 400, message: 'The name of the list must not be empty.' }, data: [] } }),
				})
			: route.fallback())

		await page.getByRole('button', { name: 'New list' }).click()
		await secretSantaApp.dialog('New list').getByRole('textbox', { name: 'Name of the list' }).fill('Broken')
		await secretSantaApp.dialog('New list').getByRole('button', { name: 'Create list' }).click()

		await expect(secretSantaApp.dialog('New list').getByText('The name of the list must not be empty.')).toBeVisible()
		await expect(secretSantaApp.listNavigationItem('Broken')).toHaveCount(0)
	})

	test('shows not found for an unknown list', async ({ secretSantaApp, page }) => {
		await page.goto('apps/secret_santa/999999')

		await expect(secretSantaApp.emptyContent('List not found')).toBeVisible()
		await expect(page).toHaveURL(/\/apps\/secret_santa\/not-found$/)
	})
})
