<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use OCA\FolderWatch\AppInfo\Application;
use OCP\IConfig;

class UserSettings {
	/** @var array<string, bool> */
	private array $ignoreOwn = [];

	public function __construct(
		private readonly IConfig $config,
	) {
	}

	/**
	 * "Ignore my own actions" (default: yes).
	 */
	public function ignoreOwnActions(string $userId): bool {
		return $this->ignoreOwn[$userId] ??= $this->config->getUserValue($userId, Application::APP_ID, 'ignore_own', 'yes') === 'yes';
	}

	public function setIgnoreOwnActions(string $userId, bool $ignore): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'ignore_own', $ignore ? 'yes' : 'no');
		$this->ignoreOwn[$userId] = $ignore;
	}

	public function reset(): void {
		$this->ignoreOwn = [];
	}
}
