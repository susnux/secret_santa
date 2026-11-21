/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OCSResponse } from '@nextcloud/typings/ocs'

import { isAxiosError } from '@nextcloud/axios'

/**
 * Get the error message an OCS API returned for a failed request
 *
 * The message is either the message of the OCS meta data or a `message` in the data.
 *
 * @param error - The error thrown by the request
 * @return The message of the server or `undefined` if there is none
 */
export function getOcsErrorMessage(error: unknown): string | undefined {
	if (!isAxiosError<Partial<OCSResponse<{ message?: string } | null>>>(error)) {
		return undefined
	}
	const ocs = error.response?.data?.ocs
	return ocs?.meta?.message || ocs?.data?.message || undefined
}

/**
 * Check if a request failed as the requested resource does not exist
 *
 * @param error - The error thrown by the request
 */
export function isNotFoundError(error: unknown): boolean {
	return isAxiosError(error) && error.response?.status === 404
}
