/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Locator, Page } from '@playwright/test'

/**
 * Page object for the Secret Santa app
 */
export class SecretSantaAppPage {
	constructor(private readonly page: Page) {}

	/**
	 * Open the start page of the app
	 */
	async open(): Promise<void> {
		await this.page.goto('apps/secret_santa/')
		await this.page.getByRole('button', { name: 'New list' }).waitFor()
	}

	/**
	 * Open a list by its URL
	 *
	 * @param listId - The id of the list
	 */
	async openList(listId: string): Promise<void> {
		await this.page.goto(`apps/secret_santa/${listId}`)
		await this.section('Participants').waitFor()
	}

	/**
	 * Send a request to the OCS API of the app as the logged in user
	 *
	 * @param method - The HTTP method
	 * @param path - The path relative to the API, e.g. `lists`
	 * @param data - The JSON body
	 * @return The data of the OCS response
	 */
	async api(method: 'GET' | 'POST' | 'PUT' | 'DELETE', path: string, data?: object): Promise<unknown> {
		const baseUrl = new URL(this.page.url() === 'about:blank' ? 'http://localhost:8089/' : this.page.url()).origin
		const response = await this.page.request.fetch(`${baseUrl}/ocs/v2.php/apps/secret_santa/api/v1/${path}`, {
			method,
			data,
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		if (!response.ok()) {
			throw new Error(`${method} ${path} failed: ${response.status()} ${await response.text()}`)
		}
		return (await response.json()).ocs.data
	}

	/**
	 * Create a list with guests through the API
	 *
	 * @param name - The name of the list
	 * @param guests - The names of the guests, their email address is derived from the name
	 * @return The id of the list
	 */
	async createListWithGuests(name: string, guests: string[]): Promise<string> {
		const { id } = await this.api('POST', 'lists', { name }) as { id: string }
		for (const guest of guests) {
			await this.api('POST', `lists/${id}/participants`, { displayName: guest, email: `${guest.toLowerCase()}@example.com` })
		}
		return id
	}

	navigation(): Locator {
		return this.page.getByRole('navigation', { name: 'Secret Santa lists' })
	}

	listNavigationItem(name: string): Locator {
		return this.navigation().getByRole('listitem').filter({ has: this.page.getByRole('link', { name, exact: true }) })
	}

	listHeading(): Locator {
		return this.page.getByRole('main').getByRole('heading', { level: 2 })
	}

	emptyContent(name: string): Locator {
		return this.page.getByText(name, { exact: true })
	}

	dialog(name: string): Locator {
		return this.page.getByRole('dialog', { name })
	}

	section(name: 'Participants' | 'Email' | 'Draw'): Locator {
		return this.page.getByRole('region', { name, exact: true })
	}

	/**
	 * The row of a participant in the participants section
	 *
	 * @param name - The name of the participant
	 */
	participant(name: string): Locator {
		return this.section('Participants').getByRole('listitem').filter({ has: this.page.getByText(name, { exact: true }) })
	}

	/**
	 * The row of a participant in the notifications of the latest draw
	 *
	 * @param name - The name of the participant
	 */
	notification(name: string): Locator {
		return this.section('Draw').getByRole('listitem').filter({ has: this.page.getByText(name, { exact: true }) })
	}

	/**
	 * Create a list with the "New list" dialog and wait until it is opened
	 *
	 * @param name - The name of the list
	 */
	async createList(name: string): Promise<void> {
		await this.page.getByRole('button', { name: 'New list' }).click()
		await this.dialog('New list').getByRole('textbox', { name: 'Name of the list' }).fill(name)
		await this.dialog('New list').getByRole('button', { name: 'Create list' }).click()
		await this.listHeading().filter({ hasText: name }).waitFor()
	}

	/**
	 * Open the actions menu of a list in the navigation and click an action
	 *
	 * @param list - The name of the list
	 * @param action - The name of the action
	 */
	async clickListAction(list: string, action: 'Rename' | 'Delete'): Promise<void> {
		await this.listNavigationItem(list).hover()
		await this.listNavigationItem(list).getByRole('button', { name: 'Actions' }).click()
		await this.page.getByRole('menuitem', { name: action }).click()
	}

	/**
	 * Add a guest with the "Add guest" dialog
	 *
	 * @param name - The name of the guest
	 * @param email - The email address of the guest
	 */
	async addGuest(name: string, email: string): Promise<void> {
		await this.section('Participants').getByRole('button', { name: 'Add guest' }).click()
		await this.fillGuestDialog('Add guest', name, email)
		await this.dialog('Add guest').getByRole('button', { name: 'Add guest' }).click()
	}

	/**
	 * Fill in the name and email address of the guest dialog
	 *
	 * @param dialog - The name of the dialog
	 * @param name - The name of the guest
	 * @param email - The email address of the guest
	 */
	async fillGuestDialog(dialog: 'Add guest' | 'Edit guest', name: string, email: string): Promise<void> {
		await this.dialog(dialog).getByRole('textbox', { name: 'Name' }).fill(name)
		await this.dialog(dialog).getByRole('textbox', { name: 'Email address' }).fill(email)
	}

	/**
	 * Open the actions menu of a participant and click an action
	 *
	 * @param participant - The name of the participant
	 * @param action - The name of the action
	 */
	async clickParticipantAction(participant: string, action: 'Partners not to draw' | 'Edit' | 'Remove'): Promise<void> {
		await this.participant(participant).getByRole('button', { name: `Actions for ${participant}` }).click()
		await this.page.getByRole('menuitem', { name: action }).click()
	}

	/**
	 * Import the partners of the latest draw of a list as exclusions
	 *
	 * @param list - The label of the list in the dialog
	 */
	async importExclusions(list: string): Promise<void> {
		await this.section('Participants').getByRole('button', { name: 'Import exclusions' }).click()
		await this.dialog('Import exclusions').getByRole('radio', { name: list, exact: true }).check({ force: true })
		await this.dialog('Import exclusions').getByRole('button', { name: 'Import' }).click()
		await this.dialog('Import exclusions').waitFor({ state: 'hidden' })
	}

	/**
	 * Draw the partners and confirm the dialog
	 */
	async draw(): Promise<void> {
		await this.section('Draw').getByRole('button', { name: 'Draw partners and send emails' }).click()
		await this.dialog('Draw the partners?').getByRole('button', { name: 'Draw and send emails' }).click()
	}
}
