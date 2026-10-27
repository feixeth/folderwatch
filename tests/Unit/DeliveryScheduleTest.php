<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\FolderWatch\Alert\DeliverySchedule;
use PHPUnit\Framework\TestCase;

class DeliveryScheduleTest extends TestCase {
	private function ts(string $when, string $zone = 'UTC'): int {
		return (new DateTimeImmutable($when, new DateTimeZone($zone)))->getTimestamp();
	}

	private function due(string $frequency, int $oldest, int $newest, int $now, string $zone = 'UTC', int $hour = 8): bool {
		return DeliverySchedule::isDue($frequency, $hour, new DateTimeZone($zone), $oldest, $newest, $now);
	}

	public function testImmediateWaitsForAQuietMoment(): void {
		$now = $this->ts('2026-10-26 10:00:00');
		$this->assertFalse($this->due('immediate', $now - 30, $now - 10, $now));
		$this->assertFalse($this->due('immediate', $now - 119, $now - 119, $now));
		$this->assertTrue($this->due('immediate', $now - 200, $now - 120, $now));
	}

	public function testImmediateNeverWaitsMoreThanTheCeilingEvenUnderContinuousActivity(): void {
		$now = $this->ts('2026-10-26 10:00:00');
		// an upload still going on: the newest row is 1 s old but the oldest is 10 minutes old
		$this->assertFalse($this->due('immediate', $now - 599, $now - 1, $now));
		$this->assertTrue($this->due('immediate', $now - 600, $now - 1, $now));
	}

	public function testHourlyDigestLeavesAtTheTurnOfTheHour(): void {
		$queued = $this->ts('2026-10-26 10:20:00');
		$this->assertFalse($this->due('hourly', $queued, $queued, $this->ts('2026-10-26 10:59:59')));
		$this->assertTrue($this->due('hourly', $queued, $queued, $this->ts('2026-10-26 11:00:00')));
		$this->assertTrue($this->due('hourly', $queued, $queued, $this->ts('2026-10-26 15:00:00')));
	}

	public function testHourlyDigestFollowsHalfHourTimeZones(): void {
		// 10:20 UTC is 15:50 in Kolkata: the digest leaves at 16:00 local = 10:30 UTC
		$queued = $this->ts('2026-10-26 10:20:00');
		$this->assertFalse($this->due('hourly', $queued, $queued, $this->ts('2026-10-26 10:29:00'), 'Asia/Kolkata'));
		$this->assertTrue($this->due('hourly', $queued, $queued, $this->ts('2026-10-26 10:30:00'), 'Asia/Kolkata'));
	}

	public function testDailyDigestLeavesAtTheChosenLocalHour(): void {
		// Paris is UTC+1 on 2026-10-26 (summer time ended on the 25th): 08:00 local = 07:00 UTC
		$queued = $this->ts('2026-10-26 07:30:00'); // 08:30 in Paris: the next 08:00 is tomorrow
		$this->assertFalse($this->due('daily', $queued, $queued, $this->ts('2026-10-27 06:59:00'), 'Europe/Paris'));
		$this->assertTrue($this->due('daily', $queued, $queued, $this->ts('2026-10-27 07:00:00'), 'Europe/Paris'));
	}

	public function testDailyDigestTakesWhatWasQueuedBeforeTodaysSlot(): void {
		$queued = $this->ts('2026-10-26 06:30:00'); // 07:30 in Paris, before 08:00 the same morning
		$this->assertFalse($this->due('daily', $queued, $queued, $this->ts('2026-10-26 06:59:00'), 'Europe/Paris'));
		$this->assertTrue($this->due('daily', $queued, $queued, $this->ts('2026-10-26 07:00:00'), 'Europe/Paris'));
	}

	public function testDailyDigestDoesNotSendWhatArrivedAfterTodaysSlotUntilTomorrow(): void {
		$queued = $this->ts('2026-10-26 09:00:00', 'Europe/Paris'); // after 08:00 local
		$this->assertFalse($this->due('daily', $queued, $queued, $this->ts('2026-10-26 20:00:00', 'Europe/Paris'), 'Europe/Paris'));
		$this->assertTrue($this->due('daily', $queued, $queued, $this->ts('2026-10-27 08:00:00', 'Europe/Paris'), 'Europe/Paris'));
	}

	public function testDailyDigestAcrossTheSummerTimeChange(): void {
		// Europe/Paris leaves summer time on 2026-10-25 at 03:00: 08:00 local is 06:00 UTC before, 07:00 UTC after
		$queued = $this->ts('2026-10-24 12:00:00', 'Europe/Paris');
		$this->assertFalse($this->due('daily', $queued, $queued, $this->ts('2026-10-25 07:59:00', 'Europe/Paris'), 'Europe/Paris'));
		$this->assertTrue($this->due('daily', $queued, $queued, $this->ts('2026-10-25 08:00:00', 'Europe/Paris'), 'Europe/Paris'));
	}

	public function testDailyDigestAtMidnightAndLateHours(): void {
		$queued = $this->ts('2026-10-26 12:00:00');
		$this->assertTrue($this->due('daily', $queued, $queued, $this->ts('2026-10-27 00:00:00'), 'UTC', 0));
		$this->assertFalse($this->due('daily', $queued, $queued, $this->ts('2026-10-26 22:59:00'), 'UTC', 23));
		$this->assertTrue($this->due('daily', $queued, $queued, $this->ts('2026-10-26 23:00:00'), 'UTC', 23));
	}

	public function testUnknownFrequencyBehavesLikeImmediate(): void {
		$now = $this->ts('2026-10-26 10:00:00');
		$this->assertTrue($this->due('whatever', $now - 300, $now - 300, $now));
		$this->assertFalse($this->due('whatever', $now - 60, $now - 60, $now));
	}
}
