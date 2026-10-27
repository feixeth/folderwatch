<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Settings;

use OCA\FolderWatch\AppInfo\Application;
use OCA\FolderWatch\Service\AppSettings;
use OCA\FolderWatch\Service\InstanceHealth;
use OCA\FolderWatch\Service\ScriptLoader;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly ScriptLoader $scripts,
		private readonly AppSettings $settings,
		private readonly InstanceHealth $health,
		private readonly IL10N $l,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin', [
			'settings' => $this->settings->toArray(),
			'defaultPatterns' => AppSettings::DEFAULT_PATTERNS,
			// the same messages as the setup checks of the administration overview
			'issues' => $this->health->getIssues($this->l),
		]);

		$this->scripts->addScript('admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
