// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { t } from './l10n.ts'

/**
 * Lets the user choose a folder in their files.
 *
 * @return the path of the folder, or null when the dialog was closed
 */
export async function pickFolder(): Promise<string | null> {
	const picker = getFilePickerBuilder(t('Choose a folder to watch'))
		.setMultiSelect(false)
		.setMimeTypeFilter(['httpd/unix-directory'])
		.allowDirectories(true)
		.startAt('/')
		.setButtonFactory((selection, path) => {
			const name = selection[0]?.basename ?? (path.split('/').filter(Boolean).pop() || '/')
			return [{
				label: t('Watch "{name}"', { name }),
				variant: 'primary',
				callback() {},
			}]
		})
		.build()

	try {
		return await picker.pick()
	} catch (error) {
		if (error instanceof FilePickerClosed) {
			return null
		}
		throw error
	}
}
