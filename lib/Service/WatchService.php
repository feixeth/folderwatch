<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use InvalidArgumentException;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * @psalm-import-type FolderWatchWatch from ResponseDefinitions
 * @psalm-import-type FolderWatchWatchStatus from ResponseDefinitions
 */
class WatchService {
	public function __construct(
		private readonly WatchMapper $watchMapper,
		private readonly QueueItemMapper $queueItemMapper,
		private readonly IRootFolder $rootFolder,
		private readonly AppSettings $settings,
		private readonly ITimeFactory $timeFactory,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l,
	) {
	}

	/**
	 * @return list<FolderWatchWatch>
	 */
	public function list(string $userId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		return array_map(
			fn (Watch $watch): array => $this->serialize($watch, $userFolder),
			$this->watchMapper->findByUser($userId),
		);
	}

	/**
	 * @return FolderWatchWatch
	 * @throws WatchException
	 */
	public function get(string $userId, int $id): array {
		return $this->serialize($this->find($userId, $id), $this->rootFolder->getUserFolder($userId));
	}

	/**
	 * @return FolderWatchWatchStatus
	 */
	public function status(string $userId, int $fileId): array {
		$watch = $this->watchMapper->findByUserAndFile($userId, $fileId);
		return ['watched' => $watch !== null, 'id' => $watch?->getId()];
	}

	/**
	 * @param array<string, mixed> $options recursive, events, channels, frequency, digestHour
	 * @return FolderWatchWatch
	 * @throws WatchException
	 */
	public function add(string $userId, ?int $fileId, ?string $path, array $options = []): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$folder = $this->resolveFolder($userFolder, $fileId, $path);

		if ($this->watchMapper->findByUserAndFile($userId, $folder->getId()) !== null) {
			throw new WatchException($this->l->t('This folder is already being watched.'), Http::STATUS_CONFLICT);
		}
		if ($this->watchMapper->countByUser($userId) >= $this->settings->getMaxWatchesPerUser()) {
			throw new WatchException(
				$this->l->n('You cannot watch more than %n folder.', 'You cannot watch more than %n folders.', $this->settings->getMaxWatchesPerUser()),
				Http::STATUS_CONFLICT,
			);
		}

		$watch = new Watch();
		$watch->setUserId($userId);
		$watch->setFileId($folder->getId());
		$watch->setCreatedAt($this->timeFactory->getTime());
		$watch->setEvents(WatchOptions::DEFAULT_EVENTS);
		$this->applyOptions($watch, $options);

		try {
			$watch = $this->watchMapper->insert($watch);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new WatchException($this->l->t('This folder is already being watched.'), Http::STATUS_CONFLICT);
			}
			throw $e;
		}

		return $this->serialize($watch, $userFolder);
	}

	/**
	 * @param array<string, mixed> $options recursive, events, channels, frequency, digestHour
	 * @return FolderWatchWatch
	 * @throws WatchException
	 */
	public function update(string $userId, int $id, array $options): array {
		$watch = $this->find($userId, $id);
		$this->applyOptions($watch, $options);
		$watch = $this->watchMapper->update($watch);
		return $this->serialize($watch, $this->rootFolder->getUserFolder($userId));
	}

	/**
	 * @throws WatchException
	 */
	public function delete(string $userId, int $id): void {
		$watch = $this->find($userId, $id);
		$this->queueItemMapper->deleteByWatch($watch->getId());
		$this->watchMapper->delete($watch);
	}

	/**
	 * @throws WatchException
	 */
	private function find(string $userId, int $id): Watch {
		try {
			return $this->watchMapper->findByIdAndUser($id, $userId);
		} catch (DoesNotExistException) {
			throw new WatchException($this->l->t('Watch not found.'), Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * @throws WatchException
	 */
	private function resolveFolder(Folder $userFolder, ?int $fileId, ?string $path): Folder {
		$node = null;
		try {
			if ($fileId !== null) {
				$node = $userFolder->getFirstNodeById($fileId);
			} elseif ($path !== null && $path !== '') {
				$node = $userFolder->get($path);
			} else {
				throw new WatchException($this->l->t('A folder is required.'));
			}
		} catch (NotFoundException|NotPermittedException) {
			$node = null;
		}

		if (!$node instanceof Node) {
			throw new WatchException($this->l->t('Folder not found.'), Http::STATUS_NOT_FOUND);
		}
		if (!$node instanceof Folder) {
			throw new WatchException($this->l->t('Only folders can be watched.'));
		}
		if (!$node->isReadable()) {
			throw new WatchException($this->l->t('Folder not found.'), Http::STATUS_NOT_FOUND);
		}
		return $node;
	}

	/**
	 * @param array<string, mixed> $options
	 * @throws WatchException
	 */
	private function applyOptions(Watch $watch, array $options): void {
		$field = '';
		try {
			if (array_key_exists('recursive', $options)) {
				$field = 'recursive';
				if (!is_bool($options['recursive'])) {
					throw new InvalidArgumentException();
				}
				$watch->setRecursive($options['recursive']);
			}
			if (array_key_exists('events', $options)) {
				$field = 'events';
				$watch->setEvents(WatchOptions::maskFromNames($this->stringList($options['events']), WatchOptions::EVENTS));
			}
			if (array_key_exists('channels', $options)) {
				$field = 'channels';
				$watch->setChannels(WatchOptions::maskFromNames($this->stringList($options['channels']), WatchOptions::CHANNELS));
			}
			if (array_key_exists('frequency', $options)) {
				$field = 'frequency';
				if (!in_array($options['frequency'], WatchOptions::FREQUENCIES, true)) {
					throw new InvalidArgumentException();
				}
				$watch->setFrequency($options['frequency']);
			}
			if (array_key_exists('digestHour', $options)) {
				$field = 'digestHour';
				$hour = $options['digestHour'];
				if (!is_int($hour) || $hour < 0 || $hour > 23) {
					throw new InvalidArgumentException();
				}
				$watch->setDigestHour($hour);
			}
		} catch (InvalidArgumentException) {
			throw new WatchException($this->l->t('Invalid value for "%s".', [$field]));
		}
	}

	/**
	 * @return list<string>
	 */
	private function stringList(mixed $value): array {
		if (!is_array($value) || !array_is_list($value)) {
			throw new InvalidArgumentException('list expected');
		}
		return $value;
	}

	/**
	 * Deletes a watch whoever owns it (administration, occ).
	 *
	 * @return bool false when there is no such watch
	 */
	public function removeById(int $id): bool {
		$watch = $this->watchMapper->find($id);
		if ($watch === null) {
			return false;
		}
		$this->queueItemMapper->deleteByWatch($id);
		$this->watchMapper->delete($watch);
		return true;
	}

	/**
	 * A watch as the API shows it, from the point of view of its owner.
	 *
	 * @return FolderWatchWatch
	 */
	public function describe(Watch $watch): array {
		return $this->serialize($watch, $this->rootFolder->getUserFolder($watch->getUserId()));
	}

	/**
	 * @return FolderWatchWatch
	 */
	private function serialize(Watch $watch, Folder $userFolder): array {
		$path = null;
		$name = null;
		$node = null;
		try {
			$node = $userFolder->getFirstNodeById($watch->getFileId());
		} catch (NotFoundException|NotPermittedException) {
		}
		if ($node instanceof Node) {
			$path = $userFolder->getRelativePath($node->getPath());
			$name = $node->getName();
		}

		return [
			'id' => $watch->getId(),
			'fileId' => $watch->getFileId(),
			'path' => $path,
			'name' => $name,
			'url' => $this->urlGenerator->linkToRouteAbsolute('files.view.showFile', ['fileid' => $watch->getFileId()]),
			'accessible' => $node instanceof Node,
			'recursive' => $watch->isRecursive(),
			'events' => WatchOptions::namesFromMask($watch->getEvents(), WatchOptions::EVENTS),
			'channels' => WatchOptions::namesFromMask($watch->getChannels(), WatchOptions::CHANNELS),
			'frequency' => $watch->getFrequency(),
			'digestHour' => $watch->getDigestHour(),
			'createdAt' => $watch->getCreatedAt(),
			'lastSentAt' => $watch->getLastSentAt(),
		];
	}
}
