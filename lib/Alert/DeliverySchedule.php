<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

use DateTimeImmutable;
use DateTimeZone;
use OCA\FolderWatch\Db\WatchOptions;

/**
 * When is a watch's pending queue worth sending?
 */
final class DeliverySchedule {
	/** "Immediate" waits for this long without new event, so that a bulk upload is one alert. */
	public const QUIET_SECONDS = 120;
	/** ... but never longer than this since the oldest pending event. */
	public const MAX_WAIT_SECONDS = 600;

	/**
	 * @param int $oldest creation time of the oldest pending row
	 * @param int $newest creation time of the newest pending row
	 */
	public static function isDue(string $frequency, int $digestHour, DateTimeZone $timeZone, int $oldest, int $newest, int $now): bool {
		return match ($frequency) {
			WatchOptions::FREQUENCY_HOURLY => $oldest < self::slotStart($now, $timeZone, null),
			WatchOptions::FREQUENCY_DAILY => $oldest < self::slotStart($now, $timeZone, $digestHour),
			default => $now - $newest >= self::QUIET_SECONDS || $now - $oldest >= self::MAX_WAIT_SECONDS,
		};
	}

	/**
	 * Start of the current digest period: the last full hour, or the last time it was $hour o'clock
	 * on the user's clock. Rows queued before it belong to a finished period.
	 */
	private static function slotStart(int $now, DateTimeZone $timeZone, ?int $hour): int {
		$local = (new DateTimeImmutable('@' . $now))->setTimezone($timeZone);
		if ($hour === null) {
			return $local->setTime((int)$local->format('G'), 0, 0)->getTimestamp();
		}
		$slot = $local->setTime(max(0, min(23, $hour)), 0, 0);
		if ($slot > $local) {
			$slot = $slot->modify('-1 day')->setTime(max(0, min(23, $hour)), 0, 0);
		}
		return $slot->getTimestamp();
	}
}
