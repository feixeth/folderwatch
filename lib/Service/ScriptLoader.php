<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use OCA\FolderWatch\AppInfo\Application;
use OCP\Util;

/**
 * The static OCP\Util calls behind a seam, so the pages that add scripts can be tested.
 */
class ScriptLoader {
	/** A script of this app, built by Vite as js/folderwatch-<name>.mjs. */
	public function addScript(string $name): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-' . $name);
	}

	/** Same, loaded before the page starts (the Files app registers its actions from init scripts). */
	public function addInitScript(string $name): void {
		Util::addInitScript(Application::APP_ID, Application::APP_ID . '-' . $name);
	}
}
