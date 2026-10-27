<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\SetupCheck;

use OCA\FolderWatch\Service\InstanceHealth;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class BackgroundJobsCheck implements ISetupCheck {
	public function __construct(
		private readonly IL10N $l,
		private readonly InstanceHealth $health,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l->t('Folder Watch: background jobs');
	}

	public function run(): SetupResult {
		if ($this->health->isCron()) {
			return SetupResult::success($this->l->t('Background jobs run with the system cron: alerts are sent on time.'));
		}
		return SetupResult::warning($this->health->describeBackgroundJobs($this->l), InstanceHealth::DOC_BACKGROUND_JOBS);
	}
}
