<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchOptions;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Looks at the file system at sending time: rights and shares may have changed since the
 * events were queued.
 */
class BatchVerifier {
	/** Checking each file costs queries; past this many entries the rest is trusted. */
	private const MAX_CHECKED = 1000;

	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The watched folder as its watcher sees it, or null when it is not reachable any more
	 * (unshared, rights removed, in the trash).
	 *
	 * @return ?array{id: int, name: string, path: string}
	 */
	public function getFolder(Watch $watch): ?array {
		try {
			$userFolder = $this->rootFolder->getUserFolder($watch->getUserId());
			$folder = $userFolder->getFirstNodeById($watch->getFileId());
			if (!$folder instanceof Folder || !$folder->isReadable()) {
				return null;
			}
			$path = $userFolder->getRelativePath($folder->getPath()) ?? '/';
			return [
				'id' => $watch->getFileId(),
				'name' => $path === '/' ? '/' : $folder->getName(),
				'path' => $path,
			];
		} catch (\Throwable $e) {
			$this->logger->debug('Folder Watch: watched folder not reachable for ' . $watch->getUserId(), ['exception' => $e, 'app' => 'folderwatch']);
			return null;
		}
	}

	/**
	 * Drops the entries the watcher cannot open any more, e.g. a file deleted again before
	 * the alert left. Removals have nothing left to open and are kept.
	 *
	 * @param list<AlertEntry> $entries
	 * @return list<AlertEntry>
	 */
	public function filterReachable(Watch $watch, array $entries): array {
		try {
			$userFolder = $this->rootFolder->getUserFolder($watch->getUserId());
		} catch (\Throwable) {
			return [];
		}

		$kept = [];
		$checked = 0;
		foreach ($entries as $entry) {
			if ($entry->event === WatchOptions::EVENT_DELETED || $checked >= self::MAX_CHECKED) {
				$kept[] = $entry;
				continue;
			}
			$checked++;
			try {
				$node = $userFolder->getFirstNodeById($entry->fileId);
			} catch (\Throwable) {
				$node = null;
			}
			if ($node !== null && $node->isReadable()) {
				$kept[] = $entry;
			}
		}
		return $kept;
	}
}
