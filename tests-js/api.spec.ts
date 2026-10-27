// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createWatch, deleteWatch, errorMessage, getStatus, listWatches, saveAdminSettings, saveIgnoreOwnActions, updateWatch } from '../src/api.ts'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => '/ocs/v2.php/' + path,
}))

const ocs = (data: unknown) => ({ data: { ocs: { data } } })

describe('api', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('lists the watches', async () => {
		vi.mocked(axios.get).mockResolvedValue(ocs([{ id: 1 }]))
		expect(await listWatches()).toEqual([{ id: 1 }])
		expect(axios.get).toHaveBeenCalledWith('/ocs/v2.php/apps/folderwatch/api/v1/watches')
	})

	it('asks the status of a folder by file ID', async () => {
		vi.mocked(axios.get).mockResolvedValue(ocs({ watched: true, id: 3 }))
		expect(await getStatus(154)).toEqual({ watched: true, id: 3 })
		expect(axios.get).toHaveBeenCalledWith('/ocs/v2.php/apps/folderwatch/api/v1/watches/status', { params: { fileId: 154 } })
	})

	it('creates a watch from a file ID or a path', async () => {
		vi.mocked(axios.post).mockResolvedValue(ocs({ id: 2 }))
		await createWatch({ fileId: 154 })
		await createWatch({ path: '/Docs' })
		expect(axios.post).toHaveBeenNthCalledWith(1, '/ocs/v2.php/apps/folderwatch/api/v1/watches', { fileId: 154 })
		expect(axios.post).toHaveBeenNthCalledWith(2, '/ocs/v2.php/apps/folderwatch/api/v1/watches', { path: '/Docs' })
	})

	it('sends only what changed when updating', async () => {
		vi.mocked(axios.patch).mockResolvedValue(ocs({ id: 2 }))
		await updateWatch(2, { frequency: 'daily', digestHour: 17 })
		expect(axios.patch).toHaveBeenCalledWith('/ocs/v2.php/apps/folderwatch/api/v1/watches/2', { frequency: 'daily', digestHour: 17 })
	})

	it('deletes a watch', async () => {
		vi.mocked(axios.delete).mockResolvedValue({})
		await deleteWatch(7)
		expect(axios.delete).toHaveBeenCalledWith('/ocs/v2.php/apps/folderwatch/api/v1/watches/7')
	})

	it('saves the personal and the admin settings', async () => {
		vi.mocked(axios.put).mockResolvedValue(ocs({ mailEnabled: false }))
		await saveIgnoreOwnActions(false)
		await saveAdminSettings({ mailEnabled: false })
		expect(axios.put).toHaveBeenNthCalledWith(1, '/ocs/v2.php/apps/folderwatch/api/v1/settings', { ignoreOwnActions: false })
		expect(axios.put).toHaveBeenNthCalledWith(2, '/ocs/v2.php/apps/folderwatch/api/v1/admin/settings', { mailEnabled: false })
	})

	describe('errorMessage', () => {
		it('prefers the message of the server', () => {
			const error = { response: { data: { ocs: { data: { message: 'Ce dossier est déjà surveillé.' } } } } }
			expect(errorMessage(error, 'fallback')).toBe('Ce dossier est déjà surveillé.')
		})

		it('falls back when there is none', () => {
			expect(errorMessage(new Error('network'), 'fallback')).toBe('fallback')
			expect(errorMessage({ response: { data: {} } }, 'fallback')).toBe('fallback')
			expect(errorMessage(undefined, 'fallback')).toBe('fallback')
		})
	})
})
