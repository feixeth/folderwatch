// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Watch } from '../src/types.ts'

export function makeWatch(overrides: Partial<Watch> = {}): Watch {
	return {
		id: 1,
		fileId: 154,
		path: '/Docs',
		name: 'Docs',
		url: 'http://localhost:8080/f/154',
		accessible: true,
		recursive: true,
		events: ['created', 'moved_in'],
		channels: ['notification', 'mail'],
		frequency: 'immediate',
		digestHour: 8,
		createdAt: 1_790_000_000,
		lastSentAt: null,
		...overrides,
	}
}
