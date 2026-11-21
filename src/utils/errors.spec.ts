/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { createOcsError } from '../test-utils.ts'
import { getOcsErrorMessage, isNotFoundError } from './errors.ts'

describe('getOcsErrorMessage', () => {
	it('returns the message of the OCS meta data', () => {
		expect(getOcsErrorMessage(createOcsError(400, 'The name must not be empty.'))).toBe('The name must not be empty.')
	})

	it('returns the message of the OCS data', () => {
		expect(getOcsErrorMessage(createOcsError(503, '', { message: 'The email could not be sent.' }))).toBe('The email could not be sent.')
	})

	it('returns undefined without message', () => {
		expect(getOcsErrorMessage(createOcsError(500))).toBeUndefined()
		expect(getOcsErrorMessage(new Error('Network error'))).toBeUndefined()
	})
})

describe('isNotFoundError', () => {
	it('detects 404 responses', () => {
		expect(isNotFoundError(createOcsError(404))).toBe(true)
		expect(isNotFoundError(createOcsError(400))).toBe(false)
		expect(isNotFoundError(new Error('Network error'))).toBe(false)
	})
})
