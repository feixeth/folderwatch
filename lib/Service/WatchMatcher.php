<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchMapper;

/**
 * Finds the watches that must be alerted for an event happening at a location.
 */
class WatchMatcher {
	/** @var array<string, list<Watch>> */
	private array $candidates = [];

	public function __construct(
		private readonly WatchMapper $watchMapper,
		private readonly AccessChecker $accessChecker,
		private readonly UserSettings $userSettings,
	) {
	}

	/**
	 * @param int $event one of WatchOptions::EVENT_*
	 * @param ?string $actorUid user who did it, null for a guest or a background process
	 * @param ?NodeLocation $excludeScopeOf watches that also cover this location are skipped
	 *                                      (moving a file inside a watched tree is not an incoming move)
	 * @return list<Watch>
	 */
	public function match(NodeLocation $location, int $event, ?string $actorUid, ?NodeLocation $excludeScopeOf = null): array {
		$matches = [];
		foreach ($this->getCandidates($location) as $watch) {
			if (($watch->getEvents() & $event) === 0) {
				continue;
			}
			if ($actorUid !== null && $actorUid === $watch->getUserId() && $this->userSettings->ignoreOwnActions($actorUid)) {
				continue;
			}
			if ($excludeScopeOf !== null && $excludeScopeOf->isCoveredBy($watch->getFileId(), $watch->isRecursive())) {
				continue;
			}
			// The watcher must still see the watched folder and the folder where it happened.
			if (!$this->accessChecker->canAccess($watch->getUserId(), $watch->getFileId())) {
				continue;
			}
			if ($location->parentId !== $watch->getFileId() && !$this->accessChecker->canAccess($watch->getUserId(), $location->parentId)) {
				continue;
			}
			$matches[] = $watch;
		}
		return $matches;
	}

	public function reset(): void {
		$this->candidates = [];
	}

	/**
	 * @return list<Watch>
	 */
	private function getCandidates(NodeLocation $location): array {
		$key = $location->parentId . ':' . count($location->chain);
		return $this->candidates[$key] ??= $this->watchMapper->findCandidates($location->parentId, $location->chain);
	}
}
