/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ISecretSantaList } from '../models/types.ts'

import { getCurrentUser } from '@nextcloud/auth'

/**
 * Check if the current user owns the list, only the owner can delete and share it
 *
 * @param list - The list
 */
export function isOwnList(list: ISecretSantaList): boolean {
	return list.owner !== null && list.owner === getCurrentUser()?.uid
}
