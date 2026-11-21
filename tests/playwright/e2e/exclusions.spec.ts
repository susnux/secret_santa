/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/secret-santa-app.ts'

test.describe('Exclusions', () => {
	test.beforeEach(async ({ secretSantaApp }) => {
		await secretSantaApp.open()
		const listId = await secretSantaApp.createListWithGuests('Christmas party', ['Alice', 'Bob', 'Carol'])
		await secretSantaApp.openList(listId)
	})

	test('excludes partners in both directions', async ({ secretSantaApp }) => {
		await secretSantaApp.clickParticipantAction('Alice', 'Partners not to draw')
		const dialog = secretSantaApp.dialog('Excluded partners')
		await dialog.getByRole('checkbox', { name: 'Bob' }).check({ force: true })
		await dialog.getByRole('button', { name: 'Save' }).click()

		await expect(dialog).toBeHidden()
		await expect(secretSantaApp.participant('Alice')).toContainText('Does not draw: Bob')
		await expect(secretSantaApp.participant('Bob')).toContainText('Does not draw: Alice')
		await expect(secretSantaApp.participant('Carol')).not.toContainText('Does not draw')
	})

	test('excludes partners in one direction', async ({ secretSantaApp, page }) => {
		await secretSantaApp.clickParticipantAction('Carol', 'Partners not to draw')
		const dialog = secretSantaApp.dialog('Excluded partners')
		await dialog.getByRole('checkbox', { name: 'Alice' }).check({ force: true })
		await dialog.getByRole('switch', { name: 'Apply the changes in both directions' }).uncheck({ force: true })
		await dialog.getByRole('button', { name: 'Save' }).click()

		await expect(secretSantaApp.participant('Carol')).toContainText('Does not draw: Alice')
		await expect(secretSantaApp.participant('Alice')).not.toContainText('Does not draw')

		// the exclusions are stored
		await page.reload()
		await expect(secretSantaApp.participant('Carol')).toContainText('Does not draw: Alice')
	})
})
