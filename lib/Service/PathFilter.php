<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

/**
 * Decides which nodes may raise an alert.
 */
class PathFilter {
	/** @var list<string>|null */
	private ?array $patterns = null;

	public function __construct(
		private readonly AppSettings $settings,
	) {
	}

	/**
	 * Only the "files" tree of a user view is tracked. This leaves out files_versions,
	 * files_trashbin, uploads (chunks), appdata_* and group folder internals.
	 */
	public function isTrackedPath(string $path): bool {
		return preg_match('#^/[^/]+/files(/|$)#', $path) === 1;
	}

	public function isIgnoredName(string $name): bool {
		foreach ($this->getPatterns() as $pattern) {
			if (fnmatch($pattern, $name, FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	public function getPatterns(): array {
		return $this->patterns ??= $this->settings->getIgnoredPatterns();
	}
}
