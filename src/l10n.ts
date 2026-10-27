// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import { translate, translatePlural } from '@nextcloud/l10n'

const APP = 'folderwatch'

/**
 * Translates a string; {placeholders} are replaced from $vars.
 */
export function t(text: string, vars?: Record<string, string | number>): string {
	return translate(APP, text, vars)
}

/**
 * Translates a plural string; %n is replaced by $count.
 */
export function n(singular: string, plural: string, count: number, vars?: Record<string, string | number>): string {
	return translatePlural(APP, singular, plural, count, vars)
}
