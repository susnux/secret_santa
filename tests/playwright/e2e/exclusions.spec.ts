/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/secret-santa-app.ts'

test.describe('Exclusions', () => {
	let listId: string

	test.beforeEach(async ({ secretSantaApp }) => {
		await secretSantaApp.open()
		listId = await secretSantaApp.createListWithGuests('Christmas party', ['Alice', 'Bob', 'Carol'])
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

	test('imports and removes the partners of an earlier draw', async ({ secretSantaApp, page }) => {
		const lastYear = await secretSantaApp.createListWithGuests('Last year', ['Alice', 'Bob', 'Carol'])
		await secretSantaApp.api('POST', `lists/${lastYear}/draws`)
		await secretSantaApp.openList(listId)

		await secretSantaApp.section('Participants').getByRole('button', { name: 'Import exclusions' }).click()
		await expect(secretSantaApp.dialog('Import exclusions').getByRole('radio', { name: 'Christmas party (this list)' })).toBeAttached()
		await secretSantaApp.dialog('Import exclusions').getByRole('button', { name: 'Cancel' }).click()
		await secretSantaApp.importExclusions('Last year')

		const note = secretSantaApp.section('Participants').getByText('Exclusions were imported from earlier draws')
		await expect(note).toBeVisible()
		// the imported partners are never shown
		await expect(secretSantaApp.section('Participants')).not.toContainText('Does not draw')
		// three participants who must not draw their partner of last year have only one possible draw left
		await secretSantaApp.draw()
		await expect(secretSantaApp.section('Draw')).toContainText('Only one draw is possible with these participants')

		await page.reload()
		await expect(note).toBeVisible()
		await secretSantaApp.section('Participants').getByRole('button', { name: 'Remove imported exclusions' }).click()
		await expect(note).toBeHidden()
		await secretSantaApp.draw()
		await expect(secretSantaApp.section('Draw').getByRole('heading', { name: 'Latest draw' })).toBeVisible()
	})
})
