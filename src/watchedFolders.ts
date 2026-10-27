// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import { loadState } from '@nextcloud/initial-state'

/**
 * IDs of the folders the user watches. The server hands them over with the Files page, so the
 * actions know what to offer at first render, without a request.
 */
const watched = new Set<number>(loadState<number[]>('folderwatch', 'watchedFileIds', []))

export function isWatched(fileId: number | undefined): boolean {
	return fileId !== undefined && watched.has(fileId)
}

export function markWatched(fileId: number): void {
	watched.add(fileId)
}

export function markUnwatched(fileId: number): void {
	watched.delete(fileId)
}
