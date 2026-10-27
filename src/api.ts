// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AdminSettings, Watch, WatchChanges } from './types.ts'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const url = (path: string) => generateOcsUrl('apps/folderwatch/api/v1' + path)

interface Ocs<T> {
	data: { ocs: { data: T } }
}

export async function listWatches(): Promise<Watch[]> {
	const { data }: Ocs<Watch[]> = await axios.get(url('/watches'))
	return data.ocs.data
}

export async function getStatus(fileId: number): Promise<{ watched: boolean, id: number | null }> {
	const { data }: Ocs<{ watched: boolean, id: number | null }> = await axios.get(url('/watches/status'), { params: { fileId } })
	return data.ocs.data
}

export async function createWatch(target: { fileId: number } | { path: string }): Promise<Watch> {
	const { data }: Ocs<Watch> = await axios.post(url('/watches'), target)
	return data.ocs.data
}

export async function updateWatch(id: number, changes: WatchChanges): Promise<Watch> {
	const { data }: Ocs<Watch> = await axios.patch(url(`/watches/${id}`), changes)
	return data.ocs.data
}

export async function deleteWatch(id: number): Promise<void> {
	await axios.delete(url(`/watches/${id}`))
}

export async function saveIgnoreOwnActions(ignoreOwnActions: boolean): Promise<void> {
	await axios.put(url('/settings'), { ignoreOwnActions })
}

export async function saveAdminSettings(settings: Partial<AdminSettings>): Promise<AdminSettings> {
	const { data }: Ocs<AdminSettings> = await axios.put(url('/admin/settings'), settings)
	return data.ocs.data
}

/**
 * The message the server gave for a failed request, if it gave one.
 */
export function errorMessage(error: unknown, fallback: string): string {
	const message = (error as { response?: { data?: { ocs?: { data?: { message?: string } } } } })?.response?.data?.ocs?.data?.message
	return message || fallback
}
