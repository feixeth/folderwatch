<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchOptions;

final class WatchFactory {
	public static function make(int $id, string $userId, int $fileId, bool $recursive = true, int $events = WatchOptions::DEFAULT_EVENTS): Watch {
		$watch = new Watch();
		$watch->setId($id);
		$watch->setUserId($userId);
		$watch->setFileId($fileId);
		$watch->setRecursive($recursive);
		$watch->setEvents($events);
		return $watch;
	}
}
