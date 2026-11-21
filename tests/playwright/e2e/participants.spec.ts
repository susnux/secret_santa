/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { runOcc } from '@nextcloud/e2e-test-server/docker'
import { createRandomUser } from '@nextcloud/e2e-test-server/playwright'
import { expect, test } from '../support/fixtures/secret-santa-app.ts'

test.describe('Participants', () => {
	test.beforeEach(async ({ secretSantaApp }) => {
		await secretSantaApp.open()
		await secretSantaApp.createList('Christmas party')
	})

	test('adds, edits and removes guests', async ({ secretSantaApp }) => {
		await secretSantaApp.addGuest('Alice', 'alice@example.com')
		await secretSantaApp.addGuest('Bob & Co', 'bob@example.com')

		await expect(secretSantaApp.dialog('Add guest')).toBeHidden()
		await expect(secretSantaApp.participant('Alice')).toContainText('alice@example.com')
		await expect(secretSantaApp.participant('Bob & Co')).toContainText('bob@example.com')

		await secretSantaApp.clickParticipantAction('Alice', 'Edit')
		await secretSantaApp.fillGuestDialog('Edit guest', 'Alice Liddell', 'liddell@example.com')
		await secretSantaApp.dialog('Edit guest').getByRole('button', { name: 'Save' }).click()
		await expect(secretSantaApp.participant('Alice Liddell')).toContainText('liddell@example.com')

		await secretSantaApp.clickParticipantAction('Bob & Co', 'Remove')
		await expect(secretSantaApp.participant('Bob & Co')).toHaveCount(0)
		await expect(secretSantaApp.section('Participants').getByRole('listitem')).toHaveCount(1)
	})

	test('shows why a guest can not be added', async ({ secretSantaApp }) => {
		await secretSantaApp.addGuest('Alice', 'alice@example.com')

		await secretSantaApp.addGuest('Alice again', 'ALICE@example.com')

		await expect(secretSantaApp.dialog('Add guest').getByText('Another participant already uses this email address.')).toBeVisible()
		await secretSantaApp.dialog('Add guest').getByRole('button', { name: 'Cancel' }).click()
		await expect(secretSantaApp.section('Participants').getByRole('listitem')).toHaveCount(1)
	})

	test('adds a Nextcloud account', async ({ secretSantaApp, page }) => {
		const other = await createRandomUser()
		try {
			await secretSantaApp.section('Participants').getByRole('combobox').fill(other.userId)
			await page.getByRole('option', { name: other.userId }).click()

			await expect(secretSantaApp.participant(other.userId)).toContainText('No email address, can not be notified')
			// already participating accounts are not offered again
			await secretSantaApp.section('Participants').getByRole('combobox').fill(other.userId)
			await expect(page.getByRole('option', { name: other.userId })).toHaveCount(0)
		} finally {
			await runOcc(['user:delete', other.userId], { failOnError: false })
		}
	})
})
