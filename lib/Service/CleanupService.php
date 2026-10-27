<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use OCA\FolderWatch\Alert\BatchVerifier;
use OCA\FolderWatch\AppInfo\Application;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Config\IUserMountCache;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Keeps the watches honest. A folder that cannot be reached is not an error: it may be in the
 * trash or unshared for a while, so its watch is only suspended (no alert, see AlertSender) and
 * wakes up when the folder comes back. It is removed when the folder is gone for good, or when
 * it stayed unreachable for a long time.
 *
 * "Gone for good" means the file ID has left the file cache: a trashed folder keeps its ID (it
 * moves to files_trashbin), a deleted one does not.
 */
class CleanupService {
	/** Default retention of the trash bin is 30 days: past that a missing folder is not coming back. */
	public const EXPIRY_SECONDS = 30 * 86400;
	/**
	 * Deleted for good: removed after this delay rather than at once, in case the answer came
	 * from a mount that was not set up (a user who never logged in) rather than from a deletion.
	 */
	public const DELETED_GRACE_SECONDS = 86400;

	private const SLICE = 200;
	private const CURSOR_KEY = 'cleanup_cursor';

	public function __construct(
		private readonly WatchMapper $watchMapper,
		private readonly QueueItemMapper $queueItemMapper,
		private readonly BatchVerifier $verifier,
		private readonly IUserManager $userManager,
		private readonly IUserMountCache $mountCache,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param int $budgetSeconds stop starting new slices after this long; the next run goes on where this one stopped
	 */
	public function run(int $budgetSeconds = 300): CleanupReport {
		$report = new CleanupReport();
		$started = $this->timeFactory->getTime();

		$cursor = $this->appConfig->getValueInt(Application::APP_ID, self::CURSOR_KEY, 0, true);
		do {
			$slice = $this->watchMapper->findAfter($cursor, self::SLICE);
			foreach ($slice as $watch) {
				$this->check($watch, $report);
				$cursor = $watch->getId();
			}
			if (count($slice) < self::SLICE) {
				$cursor = 0; // everything was seen: start over next time
				break;
			}
		} while ($this->timeFactory->getTime() - $started < $budgetSeconds);
		$this->appConfig->setValueInt(Application::APP_ID, self::CURSOR_KEY, $cursor, true);

		$report->queueOrphans = $this->queueItemMapper->deleteOrphans();
		return $report;
	}

	private function check(Watch $watch, CleanupReport $report): void {
		$report->checked++;
		$now = $this->timeFactory->getTime();

		try {
			if (!$this->userManager->userExists($watch->getUserId())) {
				$this->remove($watch, 'its owner does not exist any more');
				$report->purgedOrphanedUser++;
				return;
			}

			if ($this->verifier->getFolder($watch) !== null) {
				if ($watch->getInaccessibleSince() !== null) {
					$watch->setInaccessibleSince(null);
					$this->watchMapper->update($watch);
					$report->revived++;
				}
				return;
			}

			if ($watch->getInaccessibleSince() === null) {
				$watch->setInaccessibleSince($now);
				$this->watchMapper->update($watch);
				$report->suspended++;
				return;
			}

			$unreachableFor = $now - $watch->getInaccessibleSince();
			if ($unreachableFor >= self::DELETED_GRACE_SECONDS && $this->mountCache->getMountsForFileId($watch->getFileId()) === []) {
				$this->remove($watch, 'its folder was deleted');
				$report->purgedDeleted++;
			} elseif ($unreachableFor >= self::EXPIRY_SECONDS) {
				$this->remove($watch, 'its folder has been unreachable for a long time');
				$report->purgedExpired++;
			}
		} catch (\Throwable $e) {
			// one broken watch must not stop the others
			$this->logger->warning('Folder Watch: cleanup could not check watch ' . $watch->getId(), ['exception' => $e, 'app' => Application::APP_ID]);
		}
	}

	private function remove(Watch $watch, string $reason): void {
		$this->queueItemMapper->deleteByWatch($watch->getId());
		$this->watchMapper->delete($watch);
		$this->logger->info('Folder Watch: watch ' . $watch->getId() . ' of ' . $watch->getUserId() . ' removed, ' . $reason, ['app' => Application::APP_ID]);
	}
}
