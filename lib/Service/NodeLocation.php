<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

/**
 * Where a node sits, expressed in file IDs of its storage rather than in paths.
 */
final class NodeLocation {
	/**
	 * @param int $parentId file ID of the folder that directly contains the node
	 * @param list<int> $chain file IDs of that folder and of all its ancestors, nearest first
	 * @param array<int, string> $paths storage-internal path of each ID of the chain
	 */
	public function __construct(
		public readonly int $parentId,
		public readonly array $chain,
		public readonly array $paths,
	) {
	}

	/**
	 * Whether a watch on $watchedFileId would see an event happening at this location.
	 */
	public function isCoveredBy(int $watchedFileId, bool $recursive): bool {
		if ($watchedFileId === $this->parentId) {
			return true;
		}
		return $recursive && in_array($watchedFileId, $this->chain, true);
	}

	/**
	 * Path of a child named $name, relative to the watched folder (display only).
	 */
	public function relativePath(int $watchedFileId, string $name): string {
		if (!isset($this->paths[$this->parentId], $this->paths[$watchedFileId])) {
			return $name;
		}
		$parentPath = $this->paths[$this->parentId];
		$basePath = $this->paths[$watchedFileId];
		if (!str_starts_with($parentPath, $basePath)) {
			return $name;
		}
		$relative = trim(substr($parentPath, strlen($basePath)), '/');
		return $relative === '' ? $name : $relative . '/' . $name;
	}
}
