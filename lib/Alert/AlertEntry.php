<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

/**
 * What finally happened to one file between two alerts.
 */
final class AlertEntry {
	/**
	 * @param int $event one of WatchOptions::EVENT_*
	 */
	public function __construct(
		public readonly int $fileId,
		public int $event,
		public ?string $actorUid,
		public string $pathHint,
		public int $time,
		/** absolute link to the file as stored with the event, null for a removal or when none could be built */
		public ?string $url = null,
	) {
	}

	public function getName(): string {
		return basename($this->pathHint);
	}
}
