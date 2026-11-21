/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/secret-santa-app.ts'

test.describe('Archive', () => {
	test('archives a list, which can not be changed until it is unarchived', async ({ secretSantaApp, page }) => {
		const listId = await secretSantaApp.createListWithGuests('Last year', ['Alice', 'Bob', 'Carol'])
		await secretSantaApp.api('POST', `lists/${listId}/draws`)
		await secretSantaApp.openList(listId)

		await secretSantaApp.clickListAction('Last year', 'Archive')
		await expect(secretSantaApp.dialog('Archive list')).toContainText('Archive the list Last year?')
		await secretSantaApp.dialog('Archive list').getByRole('button', { name: 'Archive list' }).click()

		// the open list stays open, but can not be changed anymore
		await expect(secretSantaApp.dialog('Archive list')).toBeHidden()
		await expect(secretSantaApp.listNavigationItem('Last year')).toHaveCount(0)
		await expect(page.getByText('This list is archived and can not be changed.')).toBeVisible()
		await expect(secretSantaApp.archivedListsNavigationItem().getByRole('link')).toHaveAttribute('aria-current', 'page')
		await expect(secretSantaApp.section('Participants').getByText('Alice', { exact: true })).toBeVisible()
		await expect(secretSantaApp.section('Participants').getByRole('button')).toHaveCount(0)
		await expect(secretSantaApp.section('Email').getByRole('textbox')).toHaveCount(0)
		await expect(secretSantaApp.section('Draw').getByRole('button')).toHaveCount(0)
		await expect(secretSantaApp.notification('Alice')).toBeVisible()

		await secretSantaApp.archivedListsNavigationItem().getByRole('link').click()
		await expect(page).toHaveURL(/\/apps\/secret_santa\/archived$/)
		await expect(secretSantaApp.archivedList('Last year')).toBeVisible()

		// the page of the archived lists is served by the server
		await page.reload()
		await expect(secretSantaApp.archivedList('Last year')).toBeVisible()

		await secretSantaApp.archivedList('Last year').getByRole('link', { name: 'Last year' }).click()
		await expect(secretSantaApp.listHeading()).toHaveText('Last year')
		await page.getByRole('button', { name: 'Unarchive' }).click()

		await expect(page.getByText('This list is archived')).toBeHidden()
		await expect(secretSantaApp.listNavigationItem('Last year')).toBeVisible()
		await expect(secretSantaApp.section('Participants').getByRole('button', { name: 'Add guest' })).toBeVisible()
		await expect(secretSantaApp.section('Draw').getByRole('button', { name: 'Draw partners and send emails' })).toBeVisible()
	})

	test('unarchives and deletes lists on the page of the archived lists', async ({ secretSantaApp, page }) => {
		for (const name of ['Last year', 'Two years ago']) {
			const { id } = await secretSantaApp.api('POST', 'lists', { name }) as { id: string }
			await secretSantaApp.api('POST', `lists/${id}/archive`)
		}
		await page.goto('apps/secret_santa/archived')
		await expect(secretSantaApp.archivedList('Last year')).toBeVisible()
		await expect(secretSantaApp.archivedListsNavigationItem()).toContainText('2')

		await secretSantaApp.clickArchivedListAction('Last year', 'Unarchive')
		await expect(secretSantaApp.archivedList('Last year')).toHaveCount(0)
		await expect(secretSantaApp.listNavigationItem('Last year')).toBeVisible()

		await secretSantaApp.clickArchivedListAction('Two years ago', 'Delete')
		await secretSantaApp.dialog('Delete list').getByRole('button', { name: 'Delete list' }).click()
		await expect(secretSantaApp.emptyContent('No archived lists')).toBeVisible()
		await expect(secretSantaApp.listNavigationItem('Two years ago')).toHaveCount(0)
	})

	test('imports the partners of an archived list', async ({ secretSantaApp }) => {
		const lastYear = await secretSantaApp.createListWithGuests('Last year', ['Alice', 'Bob', 'Carol'])
		await secretSantaApp.api('POST', `lists/${lastYear}/draws`)
		await secretSantaApp.api('POST', `lists/${lastYear}/archive`)
		const listId = await secretSantaApp.createListWithGuests('Christmas party', ['Alice', 'Bob', 'Carol'])
		await secretSantaApp.openList(listId)

		await secretSantaApp.importExclusions('Last year (archived)')

		await expect(secretSantaApp.section('Participants').getByText('Exclusions were imported from earlier draws')).toBeVisible()
	})
})
