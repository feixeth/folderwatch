<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use OCP\IConfig;
use OCP\IL10N;

/**
 * What the instance must provide for alerts to be reliable, read from the configuration. The setup
 * checks of the administration overview and the administration page of the app show the same messages.
 *
 * @psalm-type FolderWatchIssue = array{id: string, message: string, docUrl: string}
 */
class InstanceHealth {
	public const ISSUE_BACKGROUND_JOBS = 'background_jobs';
	public const ISSUE_CLI_URL = 'cli_url';
	public const ISSUE_MAIL = 'mail';

	public const DOC_BACKGROUND_JOBS = 'https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html';
	public const DOC_CLI_URL = 'https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/config_sample_php_parameters.html#overwrite-cli-url';
	public const DOC_MAIL = 'https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/email_configuration.html';

	public function __construct(
		private readonly AppSettings $settings,
		private readonly IConfig $config,
	) {
	}

	public function isCron(): bool {
		return $this->settings->getBackgroundJobsMode() === 'cron';
	}

	public function isCliUrlUsable(): bool {
		return CliUrl::normalize($this->config->getSystemValueString('overwrite.cli.url', '')) !== null;
	}

	/**
	 * Is the mail server of Nextcloud set up (Administration settings, Basic settings, Email server)?
	 * "Set up" means a way of sending other than "none", a sender address and domain, and for SMTP a host.
	 */
	public function isMailConfigured(): bool {
		$mode = $this->config->getSystemValueString('mail_smtpmode', 'smtp');
		if ($mode === 'null') {
			return false;
		}
		if ($this->config->getSystemValueString('mail_from_address', '') === '' || $this->config->getSystemValueString('mail_domain', '') === '') {
			return false;
		}
		return $mode !== 'smtp' || $this->config->getSystemValueString('mail_smtphost', '') !== '';
	}

	public function describeBackgroundJobs(IL10N $l): string {
		return $l->t('Background jobs are not run by the system cron (current mode: %s). Folder Watch alerts then leave only when somebody visits the site, so they can be late or never come. Switch to cron.', [$this->settings->getBackgroundJobsMode()]);
	}

	public function describeCliUrl(IL10N $l): string {
		return $l->t('overwrite.cli.url is not set in config.php, or points to localhost. E-mails sent from background jobs will carry no link to the files. Set it to the public address of this Nextcloud.');
	}

	public function describeMail(IL10N $l): string {
		return $l->t('The e-mail server of Nextcloud is not configured. Folder Watch can only send Nextcloud notifications, no e-mails.');
	}

	/**
	 * @return list<FolderWatchIssue>
	 */
	public function getIssues(IL10N $l): array {
		$issues = [];
		if (!$this->isCron()) {
			$issues[] = ['id' => self::ISSUE_BACKGROUND_JOBS, 'message' => $this->describeBackgroundJobs($l), 'docUrl' => self::DOC_BACKGROUND_JOBS];
		}
		if (!$this->isCliUrlUsable()) {
			$issues[] = ['id' => self::ISSUE_CLI_URL, 'message' => $this->describeCliUrl($l), 'docUrl' => self::DOC_CLI_URL];
		}
		if (!$this->isMailConfigured()) {
			$issues[] = ['id' => self::ISSUE_MAIL, 'message' => $this->describeMail($l), 'docUrl' => self::DOC_MAIL];
		}
		return $issues;
	}
}
