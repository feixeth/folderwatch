<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use OCA\FolderWatch\Db\QueueItem;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Collects the alerts raised during a request and writes them to the queue in one go,
 * after the response, so that an upload of hundreds of files costs a few statements.
 */
class EventBuffer {
	private const FLUSH_THRESHOLD = 1000;

	/** @var array<string, QueueItem> */
	private array $items = [];
	/** @var array<int, true> */
	private array $createdIds = [];
	private bool $shutdownRegistered = false;

	public function __construct(
		private readonly QueueItemMapper $queueItemMapper,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly bool $flushOnShutdown = true,
	) {
	}

	/**
	 * A file created and then written in the same request is one "created", not two events.
	 */
	public function markCreated(int $fileId): void {
		$this->createdIds[$fileId] = true;
	}

	public function wasCreated(int $fileId): bool {
		return isset($this->createdIds[$fileId]);
	}

	/**
	 * @param ?string $url absolute link to the file, null for a removal or when none can be built
	 * @param ?string $folderUrl absolute link to the watched folder
	 */
	public function add(Watch $watch, int $fileId, int $event, ?string $actorUid, string $pathHint, ?string $url = null, ?string $folderUrl = null): void {
		$key = $watch->getId() . ':' . $fileId;
		$previous = $this->items[$key] ?? null;
		if ($previous !== null) {
			$merged = self::merge($previous->getEvent(), $event);
			if ($merged === null) {
				unset($this->items[$key]);
				return;
			}
			$event = $merged;
		}

		$item = new QueueItem();
		$item->setWatchId($watch->getId());
		$item->setUserId($watch->getUserId());
		$item->setFileId($fileId);
		$item->setEvent($event);
		$item->setActorUid($actorUid);
		$item->setPathHint(mb_substr($pathHint, 0, 1024));
		$item->setUrl($url ?? $previous?->getUrl());
		$item->setFolderUrl($folderUrl ?? $previous?->getFolderUrl());
		$item->setCreatedAt($this->timeFactory->getTime());
		$this->items[$key] = $item;

		if (count($this->items) >= self::FLUSH_THRESHOLD) {
			$this->flush();
		} elseif ($this->flushOnShutdown && !$this->shutdownRegistered) {
			// FilesystemTornDownEvent is not dispatched on WebDAV requests, so rely on PHP's own hook.
			$this->shutdownRegistered = true;
			register_shutdown_function($this->flush(...));
		}
	}

	public function count(): int {
		return count($this->items);
	}

	public function flush(): void {
		// A flush ends the unit of work: a later write to the same file is a modification.
		$this->createdIds = [];
		if ($this->items === []) {
			return;
		}
		$items = array_values($this->items);
		$this->items = [];
		try {
			$this->queueItemMapper->insertBatch($items);
		} catch (\Throwable $e) {
			$this->logger->error('Folder Watch: could not queue ' . count($items) . ' alert(s)', ['exception' => $e, 'app' => 'folderwatch']);
		}
	}

	public function reset(): void {
		$this->items = [];
		$this->createdIds = [];
	}

	/**
	 * Combines two events on the same file for the same watch within a request.
	 *
	 * @return ?int the resulting event, or null when they cancel out
	 */
	public static function merge(int $previous, int $next): ?int {
		$appeared = WatchOptions::EVENT_CREATED | WatchOptions::EVENT_MOVED_IN;
		if (($previous & $appeared) !== 0) {
			return match ($next) {
				WatchOptions::EVENT_MODIFIED => $previous,
				WatchOptions::EVENT_DELETED => null,
				default => $previous,
			};
		}
		if ($previous === WatchOptions::EVENT_DELETED && ($next & $appeared) !== 0) {
			// removed then put back: the folder sees the file as changed
			return WatchOptions::EVENT_MODIFIED;
		}
		if ($previous === WatchOptions::EVENT_MODIFIED && $next === WatchOptions::EVENT_MODIFIED) {
			return $previous;
		}
		return $next;
	}
}
