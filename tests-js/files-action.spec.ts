// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import type * as FilesModule from '@nextcloud/files'
import type { INode } from '@nextcloud/files'
import type * as ApiModule from '../src/api.ts'

import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { FileType, Permission, registerFileAction } from '@nextcloud/files'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createWatch, deleteWatch, getStatus } from '../src/api.ts'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (_app: string, stateKey: string, fallback: unknown) => stateKey === 'watchedFileIds' ? [10] : fallback }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path }))
vi.mock('@nextcloud/files', async (importOriginal) => ({
	...(await importOriginal<typeof FilesModule>()),
	registerFileAction: vi.fn(),
}))
vi.mock('../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	createWatch: vi.fn(),
	deleteWatch: vi.fn(),
	getStatus: vi.fn(),
}))

function folder(fileid: number, overrides: Partial<INode> = {}): INode {
	return { fileid, basename: 'Docs', type: FileType.Folder, permissions: Permission.ALL, ...overrides } as INode
}

const context = (nodes: INode[], viewId = 'files') => ({ nodes, view: { id: viewId }, folder: {}, contents: [] }) as never

const { watchAction, unwatchAction, indicatorAction } = await import('../src/files-action.ts')

describe('file actions', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.mocked(getCurrentUser).mockReturnValue({ uid: 'alice' } as never)
	})

	it('registers the three actions', () => {
		// registered once, when the module was loaded (before clearAllMocks)
		expect([watchAction.id, unwatchAction.id, indicatorAction.id]).toEqual(['folderwatch-watch', 'folderwatch-unwatch', 'folderwatch-indicator'])
		expect(registerFileAction).toBeDefined()
	})

	describe('what is offered', () => {
		it('offers to watch a folder that is not watched', () => {
			expect(watchAction.enabled?.(context([folder(11)]))).toBe(true)
			expect(unwatchAction.enabled?.(context([folder(11)]))).toBe(false)
			expect(indicatorAction.enabled?.(context([folder(11)]))).toBe(false)
		})

		it('offers to stop on a watched folder, and shows the indicator', () => {
			expect(watchAction.enabled?.(context([folder(10)]))).toBe(false)
			expect(unwatchAction.enabled?.(context([folder(10)]))).toBe(true)
			expect(indicatorAction.enabled?.(context([folder(10)]))).toBe(true)
			expect(indicatorAction.inline?.(context([folder(10)]) as never)).toBe(true)
		})

		it('offers nothing on a file', () => {
			const file = folder(11, { type: FileType.File })
			expect(watchAction.enabled?.(context([file]))).toBe(false)
		})

		it('offers nothing on a selection of several folders', () => {
			expect(watchAction.enabled?.(context([folder(11), folder(12)]))).toBe(false)
		})

		it('offers nothing in the trash', () => {
			expect(watchAction.enabled?.(context([folder(11)], 'trashbin'))).toBe(false)
		})

		it('offers nothing without the right to read', () => {
			expect(watchAction.enabled?.(context([folder(11, { permissions: Permission.NONE })]))).toBe(false)
		})

		it('offers nothing to a visitor who is not logged in', () => {
			vi.mocked(getCurrentUser).mockReturnValue(null)
			expect(watchAction.enabled?.(context([folder(11)]))).toBe(false)
		})

		it('does not choke on a node without an ID', () => {
			expect(watchAction.enabled?.(context([folder(0, { fileid: undefined })]))).toBe(false)
		})
	})

	describe('watching', () => {
		it('creates the watch, tells the user, and refreshes the row', async () => {
			vi.mocked(createWatch).mockResolvedValue({} as never)
			const node = folder(21)

			expect(await watchAction.exec(context([node]) as never)).toBe(true)

			expect(createWatch).toHaveBeenCalledWith({ fileId: 21 })
			expect(showSuccess).toHaveBeenCalledOnce()
			expect(emit).toHaveBeenCalledWith('files:node:updated', node)
			// the menu now offers the opposite
			expect(watchAction.enabled?.(context([node]))).toBe(false)
			expect(unwatchAction.enabled?.(context([node]))).toBe(true)
		})

		it('shows the reason given by the server and stays unwatched', async () => {
			vi.mocked(createWatch).mockRejectedValue({ response: { data: { ocs: { data: { message: 'Limite atteinte' } } } } })
			const node = folder(22)

			expect(await watchAction.exec(context([node]) as never)).toBe(false)

			expect(showError).toHaveBeenCalledWith('Limite atteinte')
			expect(emit).not.toHaveBeenCalled()
			expect(watchAction.enabled?.(context([node]))).toBe(true)
		})
	})

	describe('stopping', () => {
		it('looks up the watch of the folder, deletes it, and refreshes the row', async () => {
			vi.mocked(getStatus).mockResolvedValue({ watched: true, id: 5 })
			vi.mocked(deleteWatch).mockResolvedValue()
			const node = folder(10)

			expect(await unwatchAction.exec(context([node]) as never)).toBe(true)

			expect(getStatus).toHaveBeenCalledWith(10)
			expect(deleteWatch).toHaveBeenCalledWith(5)
			expect(emit).toHaveBeenCalledWith('files:node:updated', node)
			expect(unwatchAction.enabled?.(context([node]))).toBe(false)
			expect(watchAction.enabled?.(context([node]))).toBe(true)
		})

		it('does not call delete when the server says it is not watched (already removed elsewhere)', async () => {
			vi.mocked(getStatus).mockResolvedValue({ watched: false, id: null })
			vi.mocked(deleteWatch).mockResolvedValue()

			expect(await unwatchAction.exec(context([folder(23)]) as never)).toBe(true)
			expect(deleteWatch).not.toHaveBeenCalled()
		})

		it('reports a failure without changing the state', async () => {
			vi.mocked(getStatus).mockRejectedValue(new Error('offline'))
			const node = folder(24)
			await markAsWatched(24)

			expect(await unwatchAction.exec(context([node]) as never)).toBe(false)
			expect(showError).toHaveBeenCalledOnce()
			expect(unwatchAction.enabled?.(context([node]))).toBe(true)
		})
	})

	describe('indicator', () => {
		it('renders an eye with an accessible name', async () => {
			const element = (await indicatorAction.renderInline?.(context([folder(10)]) as never)) as HTMLElement
			expect(element.getAttribute('role')).toBe('img')
			expect(element.getAttribute('aria-label')).toBeTruthy()
			expect(element.querySelector('svg')).not.toBeNull()
		})

		it('opens the personal settings when clicked', async () => {
			const assign = vi.fn()
			vi.stubGlobal('location', { assign })
			expect(await indicatorAction.exec(context([folder(10)]) as never)).toBeNull()
			expect(assign).toHaveBeenCalledWith('/settings/user/folderwatch')
			vi.unstubAllGlobals()
		})
	})
})

async function markAsWatched(fileId: number): Promise<void> {
	const { markWatched } = await import('../src/watchedFolders.ts')
	markWatched(fileId)
}
