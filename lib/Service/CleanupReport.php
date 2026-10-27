<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

/**
 * What a cleanup run did, for logs and tests.
 */
final class CleanupReport {
	/** Watches checked in this run. */
	public int $checked = 0;
	/** Watches that became unreachable (trash, unshared). */
	public int $suspended = 0;
	/** Watches that are reachable again. */
	public int $revived = 0;
	/** Watches removed because their folder was deleted for good. */
	public int $purgedDeleted = 0;
	/** Watches removed because they stayed unreachable for too long. */
	public int $purgedExpired = 0;
	/** Watches removed because their owner does not exist any more. */
	public int $purgedOrphanedUser = 0;
	/** Queue rows removed because their watch is gone. */
	public int $queueOrphans = 0;

	/**
	 * @return array<string, int>
	 */
	public function toArray(): array {
		return get_object_vars($this);
	}
}
