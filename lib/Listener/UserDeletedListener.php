<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Listener;

use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\SendLogMapper;
use OCA\FolderWatch\Db\WatchMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Nothing of a deleted account stays behind: its watches, pending alerts and send log.
 *
 * @template-implements IEventListener<Event>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private readonly WatchMapper $watchMapper,
		private readonly QueueItemMapper $queueItemMapper,
		private readonly SendLogMapper $sendLogMapper,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		try {
			$this->queueItemMapper->deleteByUser($uid);
			$this->watchMapper->deleteByUser($uid);
			$this->sendLogMapper->deleteByUser($uid);
		} catch (\Throwable $e) {
			$this->logger->error('Folder Watch: could not clean up after user ' . $uid, ['exception' => $e, 'app' => 'folderwatch']);
		}
	}
}
