// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { IFileAction, INode } from '@nextcloud/files'

import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { FileType, Permission, registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import { createWatch, deleteWatch, errorMessage, getStatus } from './api.ts'
import { eyeOffSvg, eyeSvg } from './icons.ts'
import { t } from './l10n.ts'
import { isWatched, markUnwatched, markWatched } from './watchedFolders.ts'

const WATCH_ID = 'folderwatch-watch'
const UNWATCH_ID = 'folderwatch-unwatch'
const INDICATOR_ID = 'folderwatch-indicator'

/**
 * A single folder the user can read, outside the trash and public pages.
 */
function singleReadableFolder(nodes: INode[], viewId: string | undefined): INode | null {
	if (getCurrentUser() === null || viewId === 'trashbin' || nodes.length !== 1) {
		return null
	}
	const [node] = nodes
	if (node.type !== FileType.Folder || node.fileid === undefined || (node.permissions & Permission.READ) === 0) {
		return null
	}
	return node
}

/**
 * Makes the Files list draw the row again, so the icon and the menu entries follow.
 */
function refresh(node: INode): void {
	emit('files:node:updated', node)
}

export const watchAction: IFileAction = {
	id: WATCH_ID,
	displayName: () => t('Watch this folder'),
	title: () => t('Get an alert when something is added or changed in this folder'),
	iconSvgInline: () => eyeSvg,
	order: 90,

	enabled({ nodes, view }) {
		const node = singleReadableFolder(nodes, view?.id)
		return node !== null && !isWatched(node.fileid)
	},

	async exec({ nodes }) {
		const node = nodes[0]
		try {
			await createWatch({ fileId: node.fileid as number })
			markWatched(node.fileid as number)
			showSuccess(t('You now watch "{name}". Adjust it in your personal settings.', { name: node.basename }))
			refresh(node)
			return true
		} catch (error) {
			showError(errorMessage(error, t('Could not watch this folder.')))
			return false
		}
	},
}

export const unwatchAction: IFileAction = {
	id: UNWATCH_ID,
	displayName: () => t('Stop watching'),
	title: () => t('Stop getting alerts for this folder'),
	iconSvgInline: () => eyeOffSvg,
	order: 91,

	enabled({ nodes, view }) {
		const node = singleReadableFolder(nodes, view?.id)
		return node !== null && isWatched(node.fileid)
	},

	async exec({ nodes }) {
		const node = nodes[0]
		try {
			const status = await getStatus(node.fileid as number)
			if (status.id !== null) {
				await deleteWatch(status.id)
			}
			markUnwatched(node.fileid as number)
			showSuccess(t('You no longer watch "{name}".', { name: node.basename }))
			refresh(node)
			return true
		} catch (error) {
			showError(errorMessage(error, t('Could not stop watching this folder.')))
			return false
		}
	},
}

/** The eye shown in the row of a watched folder; clicking it opens the settings. */
export const indicatorAction: IFileAction = {
	id: INDICATOR_ID,
	displayName: () => t('Watched folder'),
	title: () => t('You get alerts for this folder. Click to manage.'),
	iconSvgInline: () => eyeSvg,
	order: 10,

	enabled({ nodes, view }) {
		const node = singleReadableFolder(nodes, view?.id)
		return node !== null && isWatched(node.fileid)
	},

	inline: () => true,

	async renderInline() {
		const icon = document.createElement('span')
		icon.className = 'folderwatch-indicator'
		icon.setAttribute('role', 'img')
		icon.setAttribute('aria-label', t('Watched folder'))
		icon.innerHTML = eyeSvg
		return icon
	},

	async exec() {
		window.location.assign(generateUrl('/settings/user/folderwatch'))
		return null
	},
}

registerFileAction(watchAction)
registerFileAction(unwatchAction)
registerFileAction(indicatorAction)
