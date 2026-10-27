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

class MailServerCheck implements ISetupCheck {
	public function __construct(
		private readonly IL10N $l,
		private readonly InstanceHealth $health,
	) {
	}

	public function getCategory(): string {
		return 'config';
	}

	public function getName(): string {
		return $this->l->t('Folder Watch: e-mail server');
	}

	public function run(): SetupResult {
		if ($this->health->isMailConfigured()) {
			return SetupResult::success($this->l->t('The e-mail server of Nextcloud is configured: alerts can be sent by e-mail.'));
		}
		return SetupResult::warning($this->health->describeMail($this->l), InstanceHealth::DOC_MAIL);
	}
}
