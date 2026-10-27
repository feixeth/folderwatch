<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Alert\AlertSender;
use OCA\FolderWatch\BackgroundJob\CleanupJob;
use OCA\FolderWatch\BackgroundJob\SendAlertsJob;
use OCA\FolderWatch\Service\CleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendAlertsJobTest extends TestCase {
	public function testTheSendingJobIsTimeSensitive(): void {
		$job = new SendAlertsJob($this->createMock(ITimeFactory::class), $this->createMock(AlertSender::class), $this->createMock(LoggerInterface::class));
		$this->assertTrue($job->isTimeSensitive());
		$this->assertSame(60, $job->getInterval());
		$this->assertFalse($job->getAllowParallelRuns());
	}

	public function testTheCleanupCanWaitForAQuietMoment(): void {
		$job = new CleanupJob($this->createMock(ITimeFactory::class), $this->createMock(CleanupService::class), $this->createMock(LoggerInterface::class));
		$this->assertFalse($job->isTimeSensitive());
		$this->assertSame(86400, $job->getInterval());
	}
}
