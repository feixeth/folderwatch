<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\BackgroundJob;

use OCA\FolderWatch\Alert\AlertSender;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs as often as the system cron does, at most once a minute.
 */
class SendAlertsJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly AlertSender $sender,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$report = $this->sender->process();
		if ($report->sent > 0 || $report->failed > 0) {
			$this->logger->info('Folder Watch: ' . json_encode($report->toArray(), JSON_THROW_ON_ERROR), ['app' => 'folderwatch']);
		}
	}
}
