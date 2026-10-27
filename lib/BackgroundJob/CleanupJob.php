<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\BackgroundJob;

use OCA\FolderWatch\Service\CleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily: suspends, revives and removes watches whose folder is not where it was.
 */
class CleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly CleanupService $cleanup,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$report = $this->cleanup->run();
		$this->logger->info('Folder Watch cleanup: ' . json_encode($report->toArray(), JSON_THROW_ON_ERROR), ['app' => 'folderwatch']);
	}
}
