<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

use OCA\FolderWatch\Db\QueueItem;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\SendLogMapper;
use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Service\AppSettings;
use OCA\FolderWatch\Service\LinkResolver;
use OCA\FolderWatch\Service\MailPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Turns the queue into notifications and mails: one of each per watched folder and per batch.
 */
class AlertSender {
	/** Rows of one watch handled per run; a bigger backlog is worked off over several runs. */
	private const MAX_ROWS_PER_WATCH = 10000;
	/** Rows that were never delivered are dropped after this long. */
	public const MAX_QUEUE_AGE = 7 * 86400;

	public function __construct(
		private readonly QueueItemMapper $queueItemMapper,
		private readonly WatchMapper $watchMapper,
		private readonly SendLogMapper $sendLogMapper,
		private readonly Aggregator $aggregator,
		private readonly BatchVerifier $verifier,
		private readonly NotificationChannel $notificationChannel,
		private readonly MailChannel $mailChannel,
		private readonly AppSettings $settings,
		private readonly MailPolicy $mailPolicy,
		private readonly LinkResolver $links,
		private readonly IUserManager $userManager,
		private readonly IDateTimeZone $dateTimeZone,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param bool $force ignore the quiet period and the digest hours (the hourly cap still applies)
	 */
	public function process(bool $force = false): SendReport {
		$report = new SendReport();
		$now = $this->timeFactory->getTime();

		$stats = $this->queueItemMapper->getPendingStats();
		uasort($stats, static fn (array $a, array $b): int => $a['oldest'] <=> $b['oldest']);

		/** @var array<string, int> $sentLastHour */
		$sentLastHour = [];
		foreach ($stats as $watchId => $stat) {
			try {
				$this->processWatch($watchId, $stat, $force, $now, $sentLastHour, $report);
			} catch (\Throwable $e) {
				$report->failed++;
				$this->logger->error('Folder Watch: could not send the alert of watch ' . $watchId, ['exception' => $e, 'app' => 'folderwatch']);
			}
		}

		$report->purged = $this->queueItemMapper->deleteOlderThan($now - self::MAX_QUEUE_AGE);
		$this->sendLogMapper->deleteOlderThan($now - 86400);
		return $report;
	}

	/**
	 * @param array{count: int, oldest: int, newest: int} $stat
	 * @param array<string, int> $sentLastHour
	 */
	private function processWatch(int $watchId, array $stat, bool $force, int $now, array &$sentLastHour, SendReport $report): void {
		$watch = $this->watchMapper->find($watchId);
		$user = $watch === null ? null : $this->userManager->get($watch->getUserId());
		if ($watch === null || $user === null || !$user->isEnabled()) {
			// watch or account is gone: nobody to tell
			$this->queueItemMapper->deleteByWatch($watchId);
			$report->dropped += $stat['count'];
			return;
		}

		if (!$force) {
			$timeZone = $this->dateTimeZone->getTimeZone($now, $watch->getUserId());
			if (!DeliverySchedule::isDue($watch->getFrequency(), $watch->getDigestHour(), $timeZone, $stat['oldest'], $stat['newest'], $now)) {
				$report->waiting++;
				return;
			}
		}

		$folder = $this->verifier->getFolder($watch);
		if ($folder === null) {
			// Suspended: unshared, rights removed or in the trash. Nothing is sent; it revives on its own.
			if ($watch->getInaccessibleSince() === null) {
				$watch->setInaccessibleSince($now);
				$this->watchMapper->update($watch);
			}
			$this->queueItemMapper->deleteByWatch($watchId);
			$report->dropped += $stat['count'];
			return;
		}
		if ($watch->getInaccessibleSince() !== null) {
			$watch->setInaccessibleSince(null);
			$this->watchMapper->update($watch);
		}

		$cap = $this->settings->getMaxSendsPerHour();
		if ($cap > 0) {
			$sentLastHour[$watch->getUserId()] ??= $this->sendLogMapper->countSince($watch->getUserId(), $now - 3600);
			if ($sentLastHour[$watch->getUserId()] >= $cap) {
				// left in the queue: it goes out with the next alert instead of being lost
				$report->capped++;
				return;
			}
		}

		$items = $this->queueItemMapper->findByWatch($watchId, self::MAX_ROWS_PER_WATCH);
		$ids = array_map(static fn (QueueItem $item): int => $item->getId(), $items);
		$entries = $this->verifier->filterReachable($watch, $this->aggregator->aggregate($items));

		$channels = $watch->getChannels();
		// the administrator switch, and a Nextcloud that cannot send mail at all
		if (!$this->mailPolicy->isAllowed()) {
			$channels &= ~WatchOptions::CHANNEL_MAIL;
		}
		if ($entries === [] || $channels === 0) {
			$this->queueItemMapper->deleteByIds($ids);
			$report->dropped += count($ids);
			return;
		}

		$folder['url'] = null;
		if (($channels & WatchOptions::CHANNEL_MAIL) !== 0) {
			// Only the mail carries links made now. They were worked out when the events happened; rows
			// without one (older rows, or events outside a web request) get what the configuration allows.
			$folder['url'] = $this->storedFolderUrl($items) ?? $this->links->fileUrl($watch->getFileId());
			foreach ($entries as $entry) {
				if ($entry->url === null && $entry->event !== WatchOptions::EVENT_DELETED) {
					$entry->url = $this->links->fileUrl($entry->fileId);
				}
			}
		}

		$summary = AlertSummary::fromEntries(
			$entries,
			$folder,
			fn (string $uid): string => $this->userManager->getDisplayName($uid) ?? $uid,
		);

		$delivered = false;
		$failed = false;
		if (($channels & WatchOptions::CHANNEL_NOTIFICATION) !== 0) {
			try {
				$this->notificationChannel->send($watch, $summary, $now);
				$delivered = true;
			} catch (\Throwable $e) {
				$failed = true;
				$this->logger->error('Folder Watch: notification failed for ' . $watch->getUserId(), ['exception' => $e, 'app' => 'folderwatch']);
			}
		}
		if (($channels & WatchOptions::CHANNEL_MAIL) !== 0) {
			try {
				if ($this->mailChannel->send($watch, $summary, $entries, $now)) {
					$delivered = true;
				} else {
					$report->mailSkipped++;
				}
			} catch (\Throwable $e) {
				$failed = true;
				$this->logger->error('Folder Watch: mail failed for ' . $watch->getUserId(), ['exception' => $e, 'app' => 'folderwatch']);
			}
		}

		if ($failed && !$delivered) {
			// every channel failed: keep the rows, the next run tries again
			$report->failed++;
			return;
		}

		$this->queueItemMapper->deleteByIds($ids);
		if ($delivered) {
			$watch->setLastSentAt($now);
			$this->watchMapper->update($watch);
			$this->sendLogMapper->record($watch->getUserId(), $now);
			$sentLastHour[$watch->getUserId()] = ($sentLastHour[$watch->getUserId()] ?? 0) + 1;
			$report->sent++;
		}
	}

	/**
	 * The link to the watched folder worked out for the most recent of these events that has one.
	 *
	 * @param list<QueueItem> $items
	 */
	private function storedFolderUrl(array $items): ?string {
		foreach (array_reverse($items) as $item) {
			if ($item->getFolderUrl() !== null) {
				return $item->getFolderUrl();
			}
		}
		return null;
	}
}
