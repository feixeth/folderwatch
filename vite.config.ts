// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'

export default createAppConfig({
	'files-action': resolve(join('src', 'files-action.ts')),
	personal: resolve(join('src', 'personal.ts')),
	admin: resolve(join('src', 'admin.ts')),
}, {
	// one stylesheet per entry, injected by the script itself
	inlineCSS: { relativeCSSInjection: true },
	config: {
		test: {
			environment: 'jsdom',
			include: ['tests-js/**/*.spec.ts'],
			globals: false,
			setupFiles: ['tests-js/setup.ts'],
			server: { deps: { inline: [/@nextcloud\/vue/] } },
		},
	},
})
