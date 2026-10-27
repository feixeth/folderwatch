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
use OCA\FolderWatch\Service\MailPolicy;
use OCA\FolderWatch\Service\ScriptLoader;
use OCA\FolderWatch\Service\UserSettings;
use OCA\FolderWatch\Service\WatchService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class Personal implements ISettings {
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly ScriptLoader $scripts,
		private readonly WatchService $watchService,
		private readonly UserSettings $userSettings,
		private readonly AppSettings $appSettings,
		private readonly MailPolicy $mailPolicy,
		private readonly InstanceHealth $health,
		private readonly IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$user = $this->userSession->getUser();
		$userId = $user?->getUID() ?? '';

		$this->initialState->provideInitialState('personal', [
			'watches' => $userId === '' ? [] : $this->watchService->list($userId),
			'ignoreOwnActions' => $userId === '' ? true : $this->userSettings->ignoreOwnActions($userId),
			'maxWatches' => $this->appSettings->getMaxWatchesPerUser(),
			// why the e-mail channel cannot be used by this person, or null
			'mailUnavailable' => $this->mailPolicy->unavailableReason($user?->getEMailAddress()),
			// without cron, alerts leave only when somebody visits the site
			'delayedAlerts' => !$this->health->isCron(),
		]);

		$this->scripts->addScript('personal');
		return new TemplateResponse(Application::APP_ID, 'personal', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
