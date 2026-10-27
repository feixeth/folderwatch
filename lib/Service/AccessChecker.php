<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Tells whether a user can still see a file, from their own point of view.
 * Covers removed shares and changed permissions. Answers are kept for the request.
 */
class AccessChecker {
	/** @var array<string, bool> */
	private array $answers = [];

	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}

	public function canAccess(string $userId, int $fileId): bool {
		$key = $userId . "\0" . $fileId;
		if (!isset($this->answers[$key])) {
			$this->answers[$key] = $this->check($userId, $fileId);
		}
		return $this->answers[$key];
	}

	public function reset(): void {
		$this->answers = [];
	}

	private function check(string $userId, int $fileId): bool {
		try {
			$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
			return $node !== null && $node->isReadable();
		} catch (\Throwable $e) {
			// Unknown user, storage not available...: no access means no alert.
			$this->logger->debug('Folder Watch: cannot check access of ' . $userId . ' to ' . $fileId, ['exception' => $e, 'app' => 'folderwatch']);
			return false;
		}
	}
}
