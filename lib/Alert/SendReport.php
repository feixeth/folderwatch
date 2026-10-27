<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

/**
 * What a run of the sender did, for logs, occ and tests.
 */
final class SendReport {
	/** Alerts delivered (one per watch and batch). */
	public int $sent = 0;
	/** Watches whose moment has not come yet. */
	public int $waiting = 0;
	/** Watches left for later because the user reached the hourly cap. */
	public int $capped = 0;
	/** Queue rows discarded: watcher lost access, files gone, no channel left. */
	public int $dropped = 0;
	/** Batches that could not be delivered and stay queued. */
	public int $failed = 0;
	/** Mails not sent because the user has no address. */
	public int $mailSkipped = 0;
	/** Rows removed because they were older than the safety limit. */
	public int $purged = 0;

	/**
	 * @return array<string, int>
	 */
	public function toArray(): array {
		return get_object_vars($this);
	}
}
