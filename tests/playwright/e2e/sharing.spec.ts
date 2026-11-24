/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { User } from '@nextcloud/e2e-test-server'

import { runOcc } from '@nextcloud/e2e-test-server/docker'
import { createRandomUser, login } from '@nextcloud/e2e-test-server/playwright'
import { expect, test } from '../support/fixtures/secret-santa-app.ts'
import { SecretSantaAppPage } from '../support/sections/SecretSantaAppPage.ts'
import { retryWithBackoff } from '../support/utils/retry.ts'

test.describe('Sharing', () => {
	let member: User
	let groupId: string

	test.beforeEach(async ({ user }) => {
		member = await retryWithBackoff('Creating a random user', () => createRandomUser())
		groupId = `family-${member.userId}`
		await runOcc(['group:add', groupId])
		await runOcc(['group:adduser', groupId, user.userId])
		await runOcc(['group:adduser', groupId, member.userId])
	})

	test.afterEach(async () => {
		await runOcc(['user:delete', member.userId], { failOnError: false })
		await runOcc(['group:delete', groupId], { failOnError: false })
	})

	test('shares a list with a group whose members can change it', async ({ secretSantaApp, page, browser, user }) => {
		const listId = await secretSantaApp.createListWithGuests('Family Christmas', ['Alice', 'Bob'])
		await secretSantaApp.openList(listId)
		await expect(secretSantaApp.section('Sharing')).toContainText('The list is not shared.')

		await secretSantaApp.section('Sharing').getByRole('combobox').fill(groupId)
		// the options of NcSelect have no accessible name, the label is only their text
		await page.getByRole('option').filter({ hasText: groupId }).click()
		await expect(secretSantaApp.section('Sharing').getByRole('list', { name: 'Groups the list is shared with' })).toContainText(groupId)

		const memberContext = await browser.newContext({ baseURL: test.info().project.use.baseURL })
		try {
			const memberPage = await memberContext.newPage()
			await retryWithBackoff('Logging in as member', () => login(memberPage.request, member))
			const memberApp = new SecretSantaAppPage(memberPage)
			await memberApp.open()

			await expect(memberApp.listNavigationItem('Family Christmas').getByRole('link')).toHaveAttribute('title', `Shared by ${user.userId}`)
			await memberApp.listNavigationItem('Family Christmas').getByRole('link').click()
			await expect(memberApp.section('Sharing')).toContainText(`Shared by ${user.userId}.`)
			await expect(memberApp.section('Sharing').getByRole('combobox')).toHaveCount(0)
			await expect(memberApp.section('Sharing').getByRole('button')).toHaveCount(0)

			// members can change the list, but not delete it
			await memberApp.addGuest('Carol', 'carol@example.com')
			await expect(memberApp.participant('Carol')).toBeVisible()
			await memberApp.listNavigationItem('Family Christmas').hover()
			await memberApp.listNavigationItem('Family Christmas').getByRole('button', { name: 'Actions' }).click()
			await expect(memberPage.getByRole('menuitem', { name: 'Archive' })).toBeVisible()
			await expect(memberPage.getByRole('menuitem', { name: 'Delete' })).toHaveCount(0)
			await memberPage.keyboard.press('Escape')

			// the owner sees the changes of the member and stops sharing
			await page.reload()
			await expect(secretSantaApp.participant('Carol')).toBeVisible()
			await secretSantaApp.section('Sharing').getByRole('button', { name: `Stop sharing with ${groupId}` }).click()
			await expect(secretSantaApp.section('Sharing')).toContainText('The list is not shared.')

			await memberPage.reload()
			await expect(memberApp.listNavigationItem('Family Christmas')).toHaveCount(0)
		} finally {
			await memberContext.close()
		}
	})

	test('members take over a list whose owner was deleted', async ({ secretSantaApp, browser, user }) => {
		const listId = await secretSantaApp.createListWithGuests('Orphaned Christmas', ['Alice', 'Bob'])
		await secretSantaApp.api('POST', `lists/${listId}/shares`, { groupId })
		await runOcc(['user:delete', user.userId])

		const memberContext = await browser.newContext({ baseURL: test.info().project.use.baseURL })
		try {
			const memberPage = await memberContext.newPage()
			await retryWithBackoff('Logging in as member', () => login(memberPage.request, member))
			const memberApp = new SecretSantaAppPage(memberPage)
			await memberApp.openList(listId)
			await expect(memberApp.section('Sharing')).toContainText('The owner of this list was deleted.')
			await expect(memberApp.section('Sharing').getByRole('combobox')).toHaveCount(0)

			await memberApp.section('Sharing').getByRole('button', { name: 'Take over list' }).click()
			await expect(memberApp.dialog('Take over list')).toContainText('Take over the list Orphaned Christmas?')
			await memberApp.dialog('Take over list').getByRole('button', { name: 'Take over list' }).click()
			await expect(memberApp.dialog('Take over list')).toHaveCount(0)

			await expect(memberApp.section('Sharing')).toContainText('Only you can delete and share the list.')
			await expect(memberApp.section('Sharing').getByRole('button', { name: 'Take over list' })).toHaveCount(0)
			await expect(memberApp.section('Sharing').getByRole('combobox')).toBeVisible()
			await expect(memberApp.listNavigationItem('Orphaned Christmas').getByRole('link')).not.toHaveAttribute('title', 'Shared with you')

			await memberApp.section('Sharing').getByRole('button', { name: `Stop sharing with ${groupId}` }).click()
			await expect(memberApp.section('Sharing')).toContainText('The list is not shared.')
			await memberApp.clickListAction('Orphaned Christmas', 'Delete')
			await memberApp.dialog('Delete list').getByRole('button', { name: 'Delete list' }).click()
			await expect(memberApp.listNavigationItem('Orphaned Christmas')).toHaveCount(0)
		} finally {
			await memberContext.close()
		}
	})
})
