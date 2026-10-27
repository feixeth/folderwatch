<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

use OCA\FolderWatch\Db\WatchOptions;

/**
 * Everything the texts of an alert need, small enough to be stored in a notification.
 *
 * @psalm-type FolderWatchSummary = array{
 *     folder: array{id: int, name: string, path: string, url: ?string},
 *     counts: array<string, int>,
 *     total: int,
 *     actors: list<array{id: string, name: string}>,
 *     unknownActor: bool,
 *     file: ?array{id: int, name: string, path: string, url: ?string, event: int},
 * }
 */
final class AlertSummary {
	/**
	 * @param array{id: int, name: string, path: string, url: ?string} $folder
	 * @param array<string, int> $counts events (WatchOptions::EVENTS names) => number of entries
	 * @param list<array{id: string, name: string}> $actors at most three distinct people, in order of appearance
	 * @param ?array{id: int, name: string, path: string, url: ?string, event: int} $file the file, when there is only one, with its path as its watcher sees it
	 */
	public function __construct(
		public readonly array $folder,
		public readonly array $counts,
		public readonly int $total,
		public readonly array $actors,
		public readonly bool $unknownActor,
		public readonly ?array $file,
	) {
	}

	/**
	 * @param list<AlertEntry> $entries
	 * @param array{id: int, name: string, path: string, url: ?string} $folder
	 * @param callable(string): string $displayName
	 */
	public static function fromEntries(array $entries, array $folder, callable $displayName): self {
		$counts = array_fill_keys(array_keys(WatchOptions::EVENTS), 0);
		$names = array_flip(WatchOptions::EVENTS);
		$actors = [];
		$unknown = false;
		foreach ($entries as $entry) {
			$counts[$names[$entry->event] ?? 'modified']++;
			if ($entry->actorUid === null) {
				$unknown = true;
			} elseif (!isset($actors[$entry->actorUid])) {
				$actors[$entry->actorUid] = ['id' => $entry->actorUid, 'name' => $displayName($entry->actorUid)];
			}
		}

		$file = null;
		if (count($entries) === 1) {
			$file = [
				'id' => $entries[0]->fileId,
				'name' => $entries[0]->getName(),
				'path' => rtrim($folder['path'], '/') . '/' . ($entries[0]->pathHint !== '' ? $entries[0]->pathHint : $entries[0]->getName()),
				'url' => $entries[0]->event === WatchOptions::EVENT_DELETED ? $folder['url'] : $entries[0]->url,
				'event' => $entries[0]->event,
			];
		}

		return new self($folder, $counts, count($entries), array_slice(array_values($actors), 0, 3), $unknown, $file);
	}

	/**
	 * @param FolderWatchSummary $data
	 */
	public static function fromArray(array $data): self {
		return new self($data['folder'], $data['counts'], $data['total'], $data['actors'], $data['unknownActor'], $data['file']);
	}

	/**
	 * @return FolderWatchSummary
	 */
	public function toArray(): array {
		return [
			'folder' => $this->folder,
			'counts' => $this->counts,
			'total' => $this->total,
			'actors' => $this->actors,
			'unknownActor' => $this->unknownActor,
			'file' => $this->file,
		];
	}

	/**
	 * The same summary without any link, for storage in a notification: its links are made when it is
	 * shown, in the web request of the person reading it.
	 */
	public function withoutUrls(): self {
		return $this->withUrls(static fn (int $fileId): ?string => null);
	}

	/**
	 * @param callable(int): ?string $urlFor link to a file or folder by its file ID
	 */
	public function withUrls(callable $urlFor): self {
		$folder = ['url' => $urlFor($this->folder['id'])] + $this->folder;
		$file = $this->file;
		if ($file !== null) {
			$file['url'] = $file['event'] === WatchOptions::EVENT_DELETED ? $folder['url'] : $urlFor($file['id']);
		}
		return new self($folder, $this->counts, $this->total, $this->actors, $this->unknownActor, $file);
	}

	/** Where "Open" leads: the file when there is only one, otherwise the folder. */
	public function getPrimaryUrl(): ?string {
		return $this->file['url'] ?? $this->folder['url'];
	}
}
