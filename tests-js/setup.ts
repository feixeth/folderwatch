// SPDX-FileCopyrightText: 2026 ZEDD
// SPDX-License-Identifier: AGPL-3.0-or-later

import { vi } from 'vitest'

// Nextcloud globals the libraries read
vi.stubGlobal('OC', { config: {}, appswebroots: {} })
vi.stubGlobal('_oc_webroot', '')
