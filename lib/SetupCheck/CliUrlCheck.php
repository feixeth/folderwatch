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

class CliUrlCheck implements ISetupCheck {
	public function __construct(
		private readonly IL10N $l,
		private readonly InstanceHealth $health,
	) {
	}

	public function getCategory(): string {
		return 'config';
	}

	public function getName(): string {
		return $this->l->t('Folder Watch: overwrite.cli.url');
	}

	public function run(): SetupResult {
		if ($this->health->isCliUrlUsable()) {
			return SetupResult::success($this->l->t('overwrite.cli.url is set: links in e-mails sent from background jobs are correct.'));
		}
		return SetupResult::warning($this->health->describeCliUrl($this->l), InstanceHealth::DOC_CLI_URL);
	}
}
