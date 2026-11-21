/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { IDraw } from '../models/types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcButton from '@nextcloud/vue/components/NcButton'
import DrawSection from './DrawSection.vue'
import { clickButton, createOcsError, createTestGuest, createTestList } from '../test-utils.ts'

const { createDraw, notifyParticipant } = vi.hoisted(() => ({ createDraw: vi.fn(), notifyParticipant: vi.fn() }))
vi.mock('../api/index.ts', () => ({ createDraw, notifyParticipant }))

const list = createTestList()
const participants = [createTestGuest('1', 'Alice'), createTestGuest('2', 'Bob'), createTestGuest('3', 'Carol')]
const drawnAt = 1_766_000_000

/**
 * Create a draw where everybody except the given participants was notified
 *
 * @param id - The id of the draw
 * @param notNotified - The ids of the participants who were not notified
 */
function createTestDraw(id: string, notNotified: string[] = []): IDraw {
	return {
		id,
		createdAt: drawnAt,
		outdated: false,
		notifications: participants.map(({ id }) => ({ participantId: id, notifiedAt: notNotified.includes(id) ? null : drawnAt + 1 })),
	}
}

/**
 * Mount the section
 *
 * @param props - The participants and draws of the list
 * @param props.participants - The participants of the list
 * @param props.draws - The draws of the list
 */
async function mountSection(props: { participants?: typeof participants, draws?: IDraw[] } = {}) {
	const wrapper = mount(DrawSection, {
		attachTo: document.body,
		props: { list, participants: props.participants ?? participants, draws: props.draws ?? [] },
	})
	await flushPromises()
	return wrapper
}

/**
 * Find the button to draw the partners
 *
 * @param wrapper - The mounted section
 */
function findDrawButton(wrapper: Awaited<ReturnType<typeof mountSection>>) {
	return wrapper.findAllComponents(NcButton).find((button) => button.text().includes('Draw partners and send emails'))!
}

describe('DrawSection', () => {
	beforeEach(() => {
		vi.useFakeTimers({ now: (drawnAt + 60) * 1000 })
		createDraw.mockReset()
		notifyParticipant.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('needs at least three participants', async () => {
		const wrapper = await mountSection({ participants: participants.slice(0, 2) })

		expect(wrapper.text()).toContain('At least 3 participants are needed to draw the partners.')
		expect(findDrawButton(wrapper).props('disabled')).toBe(true)
	})

	it('needs the email addresses of all participants', async () => {
		const wrapper = await mountSection({ participants: [...participants, { id: '4', userId: 'dave', displayName: 'Dave & Co', email: null, hasEmail: false }] })

		expect(wrapper.text()).toContain('Dave & Co has no email address and can not be notified.')
		expect(findDrawButton(wrapper).props('disabled')).toBe(true)
	})

	it('draws the partners after confirmation', async () => {
		const draw = createTestDraw('20')
		createDraw.mockResolvedValue(draw)
		const wrapper = await mountSection()
		expect(findDrawButton(wrapper).props('disabled')).toBe(false)

		await findDrawButton(wrapper).trigger('click')
		await flushPromises()
		expect(document.body.textContent).toContain('All 3 participants get an email with their partner.')
		expect(createDraw).not.toHaveBeenCalled()
		await clickButton('Draw and send emails')

		expect(createDraw).toHaveBeenCalledWith(list.id)
		expect(wrapper.emitted('update:draws')).toEqual([[[draw]]])
	})

	it('does not draw without confirmation', async () => {
		const wrapper = await mountSection({ draws: [createTestDraw('20')] })

		await findDrawButton(wrapper).trigger('click')
		await flushPromises()
		expect(document.body.textContent).toContain('The emails of the previous draw are no longer valid.')
		await clickButton('Cancel')

		expect(createDraw).not.toHaveBeenCalled()
	})

	it('shows the error of a failed draw', async () => {
		createDraw.mockRejectedValue(createOcsError(400, 'The partners can not be drawn with these exclusions.'))
		const wrapper = await mountSection()

		await findDrawButton(wrapper).trigger('click')
		await flushPromises()
		await clickButton('Draw and send emails')

		expect(wrapper.text()).toContain('The partners can not be drawn with these exclusions.')
		expect(wrapper.emitted('update:draws')).toBeUndefined()
	})

	it('shows who was notified in the latest draw and the previous draws', async () => {
		const wrapper = await mountSection({ draws: [createTestDraw('21', ['2']), createTestDraw('20')] })

		const rows = wrapper.findAll('[data-participant]')
		expect(rows.map((row) => row.attributes('data-participant'))).toEqual(['1', '2', '3'])
		expect(rows[0]!.text()).toContain('Alice')
		expect(rows[0]!.text()).toContain('Email sent')
		expect(rows[1]!.text()).toContain('Bob')
		expect(rows[1]!.text()).toContain('Email not sent')
		expect(wrapper.text()).toContain('Previous draws')
		expect(wrapper.findAll('ul').at(-1)!.findAll('li')).toHaveLength(1)
	})

	it('sends an email again', async () => {
		const updated = createTestDraw('21')
		notifyParticipant.mockResolvedValue(updated)
		const wrapper = await mountSection({ draws: [createTestDraw('21', ['2']), createTestDraw('20')] })

		await wrapper.findAll('[data-participant]')[1]!.findComponent(NcButton).trigger('click')
		await flushPromises()

		expect(notifyParticipant).toHaveBeenCalledWith(list.id, '21', '2')
		expect(wrapper.emitted('update:draws')).toEqual([[[updated, createTestDraw('20')]]])
	})

	it('asks to draw again if the latest draw is outdated', async () => {
		const wrapper = await mountSection({ draws: [{ ...createTestDraw('21', ['2']), outdated: true }] })

		expect(wrapper.text()).toContain('Participants of the latest draw were removed')
		for (const row of wrapper.findAll('[data-participant]')) {
			expect(row.findComponent(NcButton).props('disabled')).toBe(true)
		}
		expect(findDrawButton(wrapper).props('disabled')).toBe(false)
	})

	it('only shows the draws of archived lists', async () => {
		const wrapper = mount(DrawSection, {
			attachTo: document.body,
			props: {
				list: { ...list, archivedAt: drawnAt + 30 },
				participants: participants.slice(0, 1),
				draws: [{ ...createTestDraw('21', ['2']), outdated: true }, createTestDraw('20')],
			},
		})
		await flushPromises()

		expect(wrapper.findAllComponents(NcButton)).toHaveLength(0)
		expect(wrapper.text()).not.toContain('At least 3 participants are needed')
		expect(wrapper.text()).not.toContain('Draw the partners again')
		expect(wrapper.findAll('[data-participant]')).toHaveLength(3)
		expect(wrapper.text()).toContain('Previous draws')
	})

	it('shows why an email could not be sent again', async () => {
		notifyParticipant.mockRejectedValue(createOcsError(503, '', { message: 'The email could not be sent.' }))
		const wrapper = await mountSection({ draws: [createTestDraw('21', ['2'])] })

		await wrapper.findAll('[data-participant]')[1]!.findComponent(NcButton).trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('The email could not be sent.')
		expect(wrapper.emitted('update:draws')).toBeUndefined()
	})
})
