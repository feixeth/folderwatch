<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

use OCA\FolderWatch\Db\QueueItem;
use OCA\FolderWatch\Service\EventBuffer;

/**
 * Collapses the queue rows of a watch into one entry per file.
 */
class Aggregator {
	/**
	 * @param list<QueueItem> $items
	 * @return list<AlertEntry> oldest first
	 */
	public function aggregate(array $items): array {
		usort($items, static fn (QueueItem $a, QueueItem $b): int => $a->getId() <=> $b->getId());

		/** @var array<int, AlertEntry> $entries */
		$entries = [];
		foreach ($items as $item) {
			$fileId = $item->getFileId();
			if (!isset($entries[$fileId])) {
				$entries[$fileId] = new AlertEntry($fileId, $item->getEvent(), $item->getActorUid(), (string)$item->getPathHint(), $item->getCreatedAt(), $item->getUrl());
				continue;
			}

			$entry = $entries[$fileId];
			$merged = EventBuffer::merge($entry->event, $item->getEvent());
			if ($merged === null) {
				unset($entries[$fileId]);
				continue;
			}
			if ($merged !== $entry->event) {
				// the outcome changed: it is now the work of whoever changed it
				$entry->event = $merged;
				$entry->actorUid = $item->getActorUid();
				$entry->time = $item->getCreatedAt();
			}
			$entry->pathHint = (string)$item->getPathHint() ?: $entry->pathHint;
			$entry->url = $item->getUrl() ?? $entry->url;
		}

		$entries = array_values($entries);
		usort($entries, static fn (AlertEntry $a, AlertEntry $b): int => $a->time <=> $b->time);
		return $entries;
	}
}
