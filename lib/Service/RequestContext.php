<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

/**
 * Is the code running for a web request (where the address in the URL bar is known), or for a
 * command line, cron or scan (where only the configuration knows the address)?
 */
class RequestContext {
	public function isWebRequest(): bool {
		return PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
	}
}
