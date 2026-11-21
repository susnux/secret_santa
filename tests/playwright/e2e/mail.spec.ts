/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/secret-santa-app.ts'

test.describe('Email', () => {
	test.beforeEach(async ({ secretSantaApp }) => {
		await secretSantaApp.open()
		const listId = await secretSantaApp.createListWithGuests('Christmas party', ['Alice', 'Bob'])
		await secretSantaApp.openList(listId)
	})

	test('previews and saves the email', async ({ secretSantaApp, page }) => {
		const section = secretSantaApp.section('Email')
		await expect(section.getByRole('figure')).toContainText('Hello Alice,')
		await expect(section.getByRole('figure')).toContainText('the Secret Santa of Bob!')

		await section.getByRole('textbox', { name: 'Sender name' }).fill('Santa')
		await section.getByRole('textbox', { name: 'Subject' }).fill('Psst, {name}')
		await section.getByRole('textbox', { name: 'Text' }).fill('{name}, please bring a gift for {partner}.')
		await expect(section.getByRole('figure')).toContainText('Santa')
		await expect(section.getByRole('figure')).toContainText('Psst, Alice')
		await expect(section.getByRole('figure')).toContainText('Alice, please bring a gift for Bob.')

		await section.getByRole('button', { name: 'Save email' }).click()
		await expect(section.getByRole('status')).toHaveText('Email saved')
		await expect(section.getByRole('button', { name: 'Save email' })).toBeDisabled()

		await page.reload()
		await expect(section.getByRole('textbox', { name: 'Subject' })).toHaveValue('Psst, {name}')
		await expect(section.getByRole('textbox', { name: 'Text' })).toHaveValue('{name}, please bring a gift for {partner}.')
	})

	test('does not allow the partner in the subject', async ({ secretSantaApp }) => {
		const section = secretSantaApp.section('Email')

		await section.getByRole('textbox', { name: 'Subject' }).fill('A gift for {partner}')
		await section.getByRole('button', { name: 'Save email' }).click()

		await expect(section.getByText('The partner can only be mentioned in the text of the email')).toBeVisible()
	})
})
