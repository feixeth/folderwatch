// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

export type EventName = 'created' | 'modified' | 'deleted' | 'moved_in'
export type ChannelName = 'notification' | 'mail'
export type Frequency = 'immediate' | 'hourly' | 'daily'

export type MailUnavailable = 'disabled' | 'not_configured' | 'no_address'

export const EVENTS: readonly EventName[] = ['created', 'modified', 'deleted', 'moved_in']
export const CHANNELS: readonly ChannelName[] = ['notification', 'mail']

export interface Watch {
	id: number
	fileId: number
	path: string | null
	name: string | null
	url: string
	accessible: boolean
	recursive: boolean
	events: EventName[]
	channels: ChannelName[]
	frequency: Frequency
	digestHour: number
	createdAt: number
	lastSentAt: number | null
}

/** The fields of a watch the user can change. */
export type WatchChanges = Partial<Pick<Watch, 'recursive' | 'events' | 'channels' | 'frequency' | 'digestHour'>>

export interface AdminSettings {
	mailEnabled: boolean
	maxWatchesPerUser: number
	maxSendsPerHour: number
	ignoredPatterns: string[]
}

export interface PersonalState {
	watches: Watch[]
	ignoreOwnActions: boolean
	maxWatches: number
	/** why the e-mail channel cannot be used by this person, or null when it can */
	mailUnavailable: MailUnavailable | null
	/** background jobs are not run by cron: alerts leave only when somebody visits the site */
	delayedAlerts: boolean
}

export interface InstanceIssue {
	id: string
	message: string
	docUrl: string
}

export interface AdminState {
	settings: AdminSettings
	defaultPatterns: string[]
	issues: InstanceIssue[]
}
