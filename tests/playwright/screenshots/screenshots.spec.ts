/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Browser, Locator, Page } from '@playwright/test'

import { User } from '@nextcloud/e2e-test-server'
import { addUser, runOcc } from '@nextcloud/e2e-test-server/docker'
import { login } from '@nextcloud/e2e-test-server/playwright'
import { expect, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { SecretSantaAppPage } from '../support/sections/SecretSantaAppPage.ts'

const OUTPUT = 'docs/screenshots'
const PASSWORD = 'Merry-Christmas-2026'

// fresh accounts on every run, as draws are rate limited per account
const SUFFIX = Math.random().toString(36).slice(2, 8)
const ACCOUNTS = {
	[`emma-${SUFFIX}`]: { name: 'Emma Fischer', avatar: '⛄', color: '#d7ecf7' },
	[`noah-${SUFFIX}`]: { name: 'Noah Fischer', avatar: '🦌', color: '#f6e3cf' },
	[`lucas-${SUFFIX}`]: { name: 'Lucas Weber', avatar: '🐧', color: '#dcefdc' },
	[`sophia-${SUFFIX}`]: { name: 'Sophia Becker', avatar: '🎄', color: '#f7dada' },
}
const ORGANIZER = new User(`emma-${SUFFIX}`, PASSWORD)

type Participant = { userId: string } | { displayName: string, email: string }

const FAMILY: Record<string, Participant> = {
	'Emma Fischer': { userId: `emma-${SUFFIX}` },
	'Noah Fischer': { userId: `noah-${SUFFIX}` },
	'Grandma Rose': { displayName: 'Grandma Rose', email: 'rose@example.com' },
	'Olivia Brown': { displayName: 'Olivia Brown', email: 'olivia.brown@example.com' },
	'Liam Brown': { displayName: 'Liam Brown', email: 'liam.brown@example.com' },
	'Mia Fischer': { displayName: 'Mia Fischer', email: 'mia@example.com' },
}

const MAIL = {
	senderName: 'Santa\'s little helper',
	mailSubject: 'Ho ho ho, {name}! 🎅',
	mailBody: `Hi {name},

this year you are the Secret Santa of {partner}! 🎁

We exchange the gifts on Christmas Eve at Grandma's house, please keep the gift around 30 €.

Merry Christmas,
Emma`,
}

/** Arrow drawn at the mouse position, as screenshots do not contain the system cursor */
const CURSOR_SCRIPT = `
window.addEventListener('DOMContentLoaded', () => {
	const cursor = document.createElement('div')
	cursor.setAttribute('aria-hidden', 'true')
	cursor.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"><path d="M5 2.5v17l4.5-4.2 3 6.7 3-1.4-3-6.6h6.2z" fill="#000" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg>'
	cursor.style.cssText = 'position:fixed;z-index:2147483647;pointer-events:none;left:-100px;top:-100px;margin:-3px 0 0 -6px;transform-origin:6px 3px;transition:transform .1s'
	document.body.append(cursor)
	document.addEventListener('mousemove', (event) => {
		cursor.style.left = event.clientX + 'px'
		cursor.style.top = event.clientY + 'px'
	}, true)
	document.addEventListener('mousedown', () => { cursor.style.transform = 'scale(0.8)' }, true)
	document.addEventListener('mouseup', () => { cursor.style.transform = '' }, true)
})`

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ browser }) => {
	for (const [userId, { name, avatar, color }] of Object.entries(ACCOUNTS)) {
		const user = new User(userId, PASSWORD)
		await addUser(user)
		await runOcc(['user:setting', userId, 'settings', 'display_name', name])
		await runOcc(['user:setting', userId, 'settings', 'email', `${name.split(' ')[0]!.toLowerCase()}@example.com`])
		await setAvatar(browser, user, avatar, color)
	}

	const { app, page } = await openAsOrganizer(browser)
	const lastYear = await createList(app, 'Family Christmas 2025', Object.values(FAMILY))
	await app.api('POST', `lists/${lastYear.id}/draws`)

	const list = await createList(app, 'Family Christmas 2026', Object.values(FAMILY))
	const [emma, noah, , olivia, liam] = list.participants
	await app.api('PUT', `lists/${list.id}/participants/${emma}/exclusions`, { receiverIds: [noah], mutual: true })
	await app.api('PUT', `lists/${list.id}/participants/${olivia}/exclusions`, { receiverIds: [liam], mutual: true })
	await app.api('POST', `lists/${list.id}/exclusions/import`, { sourceListId: lastYear.id })
	await app.api('PUT', `lists/${list.id}`, { name: 'Family Christmas 2026', ...MAIL })
	await page.context().close()
})

test.afterAll(async () => {
	for (const userId of Object.keys(ACCOUNTS)) {
		await runOcc(['user:delete', userId], { failOnError: false })
	}
})

test('demo of filling a list', async ({ browser }) => {
	const { app, page } = await openAsOrganizer(browser)
	await page.addInitScript(CURSOR_SCRIPT)
	await app.open()
	await page.mouse.move(640, 400)

	const recording = await startRecording(page)
	await page.waitForTimeout(800)

	await click(page, page.getByRole('button', { name: 'New list' }))
	await type(app.dialog('New list').getByRole('textbox', { name: 'Name of the list' }), 'Office party')
	await click(page, app.dialog('New list').getByRole('button', { name: 'Create list' }))
	await app.listHeading().filter({ hasText: 'Office party' }).waitFor()
	await page.waitForTimeout(600)

	for (const name of ['Lucas Weber', 'Sophia Becker']) {
		const search = app.section('Participants').getByRole('combobox')
		await click(page, search)
		await type(search, name.split(' ')[0]!)
		await click(page, page.getByRole('option', { name: new RegExp(name) }))
		await expect(app.participant(name)).toBeVisible()
		await page.waitForTimeout(400)
	}

	for (const [name, email] of [['Ava Wilson', 'ava.wilson@example.com'], ['Ethan Clark', 'ethan.clark@example.com']] as const) {
		await click(page, app.section('Participants').getByRole('button', { name: 'Add guest' }))
		await type(app.dialog('Add guest').getByRole('textbox', { name: 'Name' }), name)
		await type(app.dialog('Add guest').getByRole('textbox', { name: 'Email address' }), email)
		await click(page, app.dialog('Add guest').getByRole('button', { name: 'Add guest' }))
		await expect(app.participant(name)).toBeVisible()
		await page.waitForTimeout(400)
	}

	await page.mouse.move(900, 600, { steps: 15 })
	await page.waitForTimeout(500)
	await recording.stop(join(OUTPUT, 'demo.webp'))
	await page.context().close()
})

test('screenshots of a list', async ({ browser }) => {
	const { app, page } = await openAsOrganizer(browser)
	await app.open()
	await page.getByRole('link', { name: 'Family Christmas 2026' }).click()
	await expect(app.participant('Olivia Brown')).toContainText('Does not draw: Liam Brown')
	await settle(page)
	await page.screenshot({ path: join(OUTPUT, 'participants.png') })

	await app.clickParticipantAction('Olivia Brown', 'Partners not to draw')
	await expect(app.dialog('Excluded partners').getByRole('checkbox', { name: 'Liam Brown' })).toBeChecked()
	await page.mouse.move(0, 0)
	await settle(page)
	await page.screenshot({ path: join(OUTPUT, 'exclusions.png') })
	await app.dialog('Excluded partners').getByRole('button', { name: 'Cancel' }).click()
	await expect(app.dialog('Excluded partners')).toBeHidden()

	await scrollToTop(app.section('Email'))
	await settle(page)
	await page.screenshot({ path: join(OUTPUT, 'email.png') })

	await app.draw()
	for (const name of Object.keys(FAMILY)) {
		await expect(app.notification(name)).toContainText('Email sent')
	}
	await scrollToTop(app.section('Draw'))
	await page.mouse.move(0, 0)
	await settle(page)
	await page.screenshot({ path: join(OUTPUT, 'draw.png') })
	await page.context().close()
})

/**
 * Open a new page logged in as the organizer
 *
 * @param browser - The browser to open the page in
 */
async function openAsOrganizer(browser: Browser): Promise<{ app: SecretSantaAppPage, page: Page }> {
	const context = await browser.newContext()
	await login(context.request, ORGANIZER)
	const page = await context.newPage()
	const app = new SecretSantaAppPage(page)
	await app.open()
	return { app, page }
}

/**
 * Set an emoji as avatar of an account
 *
 * @param browser - The browser to render the avatar with
 * @param user - The account
 * @param emoji - The emoji to show
 * @param color - The background color
 */
async function setAvatar(browser: Browser, user: User, emoji: string, color: string): Promise<void> {
	const context = await browser.newContext()
	await login(context.request, user)
	const page = await context.newPage()
	await page.setContent(`<div style="display:flex;align-items:center;justify-content:center;width:512px;height:512px;background:${color};font-size:340px">${emoji}</div>`)
	const image = await page.locator('div').screenshot()
	const response = await context.request.post('avatar/', {
		headers: { 'OCS-APIRequest': 'true' },
		multipart: { 'files[]': { name: 'avatar.png', mimeType: 'image/png', buffer: image } },
	})
	expect(response.ok()).toBe(true)
	await context.close()
}

/**
 * Create a list with participants through the API
 *
 * @param app - The page object of the app
 * @param name - The name of the list
 * @param participants - The participants to add
 * @return The id of the list and the ids of the participants in the given order
 */
async function createList(app: SecretSantaAppPage, name: string, participants: Participant[]): Promise<{ id: string, participants: string[] }> {
	const { id } = await app.api('POST', 'lists', { name }) as { id: string }
	const ids: string[] = []
	for (const participant of participants) {
		const { id: participantId } = await app.api('POST', `lists/${id}/participants`, participant) as { id: string }
		ids.push(participantId)
	}
	return { id, participants: ids }
}

/**
 * Move the mouse visibly to an element and click it
 *
 * @param page - The page
 * @param locator - The element to click
 */
async function click(page: Page, locator: Locator): Promise<void> {
	await locator.scrollIntoViewIfNeeded()
	const box = await locator.boundingBox()
	if (box) {
		await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 20 })
		await page.waitForTimeout(200)
	}
	await locator.click()
}

/**
 * Type into a field with a visible delay between the keys
 *
 * @param locator - The field
 * @param text - The text to type
 */
async function type(locator: Locator, text: string): Promise<void> {
	await locator.pressSequentially(text, { delay: 70 })
	await locator.page().waitForTimeout(300)
}

/**
 * Wait until all images are loaded and transitions are finished
 *
 * @param page - The page
 */
async function settle(page: Page): Promise<void> {
	await page.waitForLoadState('networkidle')
	await page.waitForFunction(() => [...document.images].every((image) => image.complete))
	await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running' || animation.effect?.getTiming().iterations === Infinity))
}

/**
 * Scroll the app content so the element is at its top
 *
 * @param locator - The element
 */
async function scrollToTop(locator: Locator): Promise<void> {
	await locator.evaluate((element) => element.scrollIntoView({ block: 'start' }))
}

/**
 * Record the page with the screencast of Chromium,
 * which only sends a frame when the page changed.
 *
 * @param page - The page to record
 * @return Stops the recording and saves the frames as animated WebP
 */
async function startRecording(page: Page): Promise<{ stop: (path: string) => Promise<void> }> {
	const frames: { data: string, timestamp: number }[] = []
	const cdp = await page.context().newCDPSession(page)
	cdp.on('Page.screencastFrame', ({ data, metadata, sessionId }) => {
		frames.push({ data, timestamp: metadata.timestamp ?? Date.now() / 1000 })
		cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {})
	})
	await cdp.send('Page.startScreencast', { format: 'png' })

	return {
		async stop(path: string) {
			await cdp.send('Page.stopScreencast')
			const end = Date.now() / 1000

			// at most 15 frames per second, a newer frame replaces the content of a too close one
			const kept: typeof frames = []
			for (const frame of frames) {
				const last = kept.at(-1)
				if (last && frame.timestamp - last.timestamp < 1 / 15) {
					last.data = frame.data
				} else {
					kept.push({ ...frame })
				}
			}

			const dir = mkdtempSync(join(tmpdir(), 'secret-santa-demo-'))
			try {
				const args = ['-loop', '0', '-mixed', '-q', '80', '-m', '6']
				kept.forEach((frame, index) => {
					const file = join(dir, `${String(index).padStart(5, '0')}.png`)
					writeFileSync(file, Buffer.from(frame.data, 'base64'))
					const next = kept[index + 1]?.timestamp ?? end + 2
					args.push('-d', String(Math.max(1, Math.round((next - frame.timestamp) * 1000))), file)
				})
				mkdirSync(OUTPUT, { recursive: true })
				execFileSync('img2webp', [...args, '-o', path])
			} finally {
				rmSync(dir, { recursive: true, force: true })
			}
		},
	}
}
