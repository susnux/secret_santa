/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/secret-santa-app.ts'

const GUESTS = ['Alice', 'Bob', 'Carol', 'Dave', 'Erin']

test.describe('Draw', () => {
	test.beforeEach(async ({ secretSantaApp }) => {
		await secretSantaApp.open()
	})

	test('needs at least three participants', async ({ secretSantaApp }) => {
		await secretSantaApp.openList(await secretSantaApp.createListWithGuests('Christmas party', ['Alice', 'Bob']))

		await expect(secretSantaApp.section('Draw')).toContainText('At least 3 participants are needed to draw the partners.')
		await expect(secretSantaApp.section('Draw').getByRole('button', { name: 'Draw partners and send emails' })).toBeDisabled()
	})

	test('draws the partners and sends the emails', async ({ secretSantaApp }) => {
		await secretSantaApp.openList(await secretSantaApp.createListWithGuests('Christmas party', GUESTS))

		await secretSantaApp.draw()

		await expect(secretSantaApp.section('Draw').getByRole('heading', { name: 'Latest draw' })).toBeVisible()
		for (const guest of GUESTS) {
			await expect(secretSantaApp.notification(guest)).toContainText('Email sent')
		}

		await secretSantaApp.notification('Carol').getByRole('button', { name: 'Send the email to Carol again' }).click()
		await expect(secretSantaApp.notification('Carol')).toContainText('Email sent')

		// a second draw replaces the first one
		await secretSantaApp.draw()
		await expect(secretSantaApp.section('Draw').getByRole('heading', { name: 'Previous draws' })).toBeVisible()
		await expect(secretSantaApp.dialog('Draw the partners?')).toBeHidden()
	})

	test('does not draw without confirmation', async ({ secretSantaApp }) => {
		await secretSantaApp.openList(await secretSantaApp.createListWithGuests('Christmas party', GUESTS))

		await secretSantaApp.section('Draw').getByRole('button', { name: 'Draw partners and send emails' }).click()
		await secretSantaApp.dialog('Draw the partners?').getByRole('button', { name: 'Cancel' }).click()

		await expect(secretSantaApp.section('Draw').getByRole('heading', { name: 'Latest draw' })).toHaveCount(0)
	})

	test('refuses a draw that is the only possible one', async ({ secretSantaApp }) => {
		const listId = await secretSantaApp.createListWithGuests('Christmas party', ['Alice', 'Bob', 'Carol'])
		const participants = await secretSantaApp.api('GET', `lists/${listId}/participants`) as { id: string }[]
		// one exclusion in one direction leaves only one possible draw of three participants
		await secretSantaApp.api('PUT', `lists/${listId}/participants/${participants[0]!.id}/exclusions`, { receiverIds: [participants[1]!.id], mutual: false })
		await secretSantaApp.openList(listId)

		await secretSantaApp.draw()

		await expect(secretSantaApp.section('Draw')).toContainText('Only one draw is possible with these participants')
		await expect(secretSantaApp.section('Draw').getByRole('heading', { name: 'Latest draw' })).toHaveCount(0)
	})

	test('asks to draw again after a participant was removed', async ({ secretSantaApp }) => {
		await secretSantaApp.openList(await secretSantaApp.createListWithGuests('Christmas party', GUESTS))
		await secretSantaApp.draw()
		await expect(secretSantaApp.notification('Alice')).toContainText('Email sent')

		await secretSantaApp.clickParticipantAction('Erin', 'Remove')

		await expect(secretSantaApp.section('Draw')).toContainText('Participants of the latest draw were removed')
		await expect(secretSantaApp.notification('Alice').getByRole('button', { name: 'Send the email to Alice again' })).toBeDisabled()
	})

	test('shows emails that could not be sent', async ({ secretSantaApp, page }) => {
		const listId = await secretSantaApp.createListWithGuests('Christmas party', GUESTS)
		await page.route('**/apps/secret_santa/api/v1/lists/*/draws', async (route) => {
			if (route.request().method() !== 'POST') {
				return route.fallback()
			}
			const response = await route.fetch()
			const body = await response.json()
			body.ocs.data.notifications[0].notifiedAt = null
			return route.fulfill({ response, json: body })
		})
		await page.route('**/apps/secret_santa/api/v1/lists/*/draws/*/participants/*/notify', (route) => route.fulfill({
			status: 503,
			contentType: 'application/json',
			body: JSON.stringify({ ocs: { meta: { status: 'failure', statuscode: 503, message: '' }, data: { message: 'The email could not be sent.' } } }),
		}))
		await secretSantaApp.openList(listId)

		await secretSantaApp.draw()
		const failed = secretSantaApp.section('Draw').getByRole('listitem').filter({ hasText: 'Email not sent' })
		await expect(failed).toHaveCount(1)
		await failed.getByRole('button', { name: /^Send the email to .+ again$/ }).click()

		await expect(secretSantaApp.section('Draw').getByText('The email could not be sent.')).toBeVisible()
	})
})
