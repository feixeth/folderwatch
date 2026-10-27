<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Listener;

use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Service\AncestorResolver;
use OCA\FolderWatch\Service\EventBuffer;
use OCA\FolderWatch\Service\LinkResolver;
use OCA\FolderWatch\Service\NodeLocation;
use OCA\FolderWatch\Service\PathFilter;
use OCA\FolderWatch\Service\WatchMatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Turns file system events into queue rows. It does not send anything and must never
 * make the file operation fail.
 *
 * What the server dispatches, as observed on Nextcloud 35 (see README):
 *  - new file or folder (PUT, MKCOL, chunked upload, copy): NodeCreated then NodeWritten
 *  - overwrite: NodeWritten only
 *  - move or rename: NodeRenamed (the source node has no ID any more)
 *  - delete or move to trash: NodeDeleted
 *  - restore from the trash: NodeTouched (ignored, nothing new appears)
 *
 * @template-implements IEventListener<Event>
 */
class NodeEventsListener implements IEventListener {
	public function __construct(
		private readonly AncestorResolver $resolver,
		private readonly WatchMatcher $matcher,
		private readonly LinkResolver $links,
		private readonly EventBuffer $buffer,
		private readonly PathFilter $pathFilter,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof NodeCreatedEvent) {
				$node = $event->getNode();
				if ($this->isTracked($node)) {
					$this->buffer->markCreated($node->getId());
					$this->record($node, WatchOptions::EVENT_CREATED);
				}
			} elseif ($event instanceof NodeWrittenEvent) {
				$node = $event->getNode();
				if ($this->isTracked($node) && !$this->buffer->wasCreated($node->getId())) {
					$this->record($node, WatchOptions::EVENT_MODIFIED);
				}
			} elseif ($event instanceof NodeDeletedEvent) {
				$node = $event->getNode();
				if ($this->isTracked($node)) {
					$this->record($node, WatchOptions::EVENT_DELETED);
				}
				$this->forgetLocationsIfFolder($node);
			} elseif ($event instanceof NodeRenamedEvent) {
				$this->onRenamed($event->getSource(), $event->getTarget());
				$this->forgetLocationsIfFolder($event->getTarget());
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Folder Watch: could not process a file event', ['exception' => $e, 'app' => 'folderwatch']);
		}
	}

	private function onRenamed(Node $source, Node $target): void {
		$from = $this->isTracked($source) ? $this->resolver->locate($source) : null;
		$to = $this->isTracked($target) ? $this->resolver->locate($target) : null;

		// Renaming inside one folder is not reported.
		if ($from !== null && $to !== null && $from->parentId === $to->parentId) {
			return;
		}

		// The moved node keeps its file ID, only the source object has lost it.
		$fileId = $target->getId();
		if ($to !== null) {
			$this->add($to, $target->getName(), $fileId, WatchOptions::EVENT_MOVED_IN, $from);
		}
		if ($from !== null) {
			$this->add($from, $source->getName(), $fileId, WatchOptions::EVENT_DELETED, $to);
		}
	}

	private function record(Node $node, int $event): void {
		$location = $this->resolver->locate($node);
		if ($location !== null) {
			$this->add($location, $node->getName(), $node->getId(), $event, null);
		}
	}

	private function add(NodeLocation $location, string $name, int $fileId, int $event, ?NodeLocation $excludeScopeOf): void {
		$actor = $this->userSession->getUser()?->getUID();
		foreach ($this->matcher->match($location, $event, $actor, $excludeScopeOf) as $watch) {
			$this->buffer->add(
				$watch,
				$fileId,
				$event,
				$actor,
				$location->relativePath($watch->getFileId(), $name),
				// the link is worked out here, in the request that did it: it knows the address of the server
				$event === WatchOptions::EVENT_DELETED ? null : $this->links->fileUrl($fileId),
				$this->links->fileUrl($watch->getFileId()),
			);
		}
	}

	private function isTracked(Node $node): bool {
		return $this->pathFilter->isTrackedPath($node->getPath()) && !$this->pathFilter->isIgnoredName($node->getName());
	}

	/**
	 * Cached locations are keyed by path: once a folder moved or disappeared they may be wrong.
	 */
	private function forgetLocationsIfFolder(Node $node): void {
		if ($node instanceof Folder) {
			$this->resolver->reset();
			$this->matcher->reset();
			$this->links->reset();
		}
	}
}
