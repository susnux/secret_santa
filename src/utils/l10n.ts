/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Options of `t()` for translations rendered as text by Vue, which escapes them itself.
 * Without them placeholder values like names would be escaped twice.
 */
export const PLAIN_TEXT = { escape: false, sanitize: false }
