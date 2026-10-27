<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Builds the list of ancestor file IDs of a node.
 *
 * The chain is read from the storage cache (the parent column of filecache), never from
 * paths, so it does not depend on whose view the node was reached through:
 *  - when the node is reached through a share, the chain is read from the owner's storage,
 *    which lets an owner watching a folder above the share point see what a recipient does;
 *  - group folders and external storages have no owner, their own cache is walked up to the
 *    root of the mount.
 * A watch only matches inside the storage chain: it does not cross mount boundaries.
 */
class AncestorResolver {
	private const MAX_DEPTH = 64;
	private const MAX_MEMO = 2000;

	/** @var array<string, NodeLocation|null> */
	private array $locations = [];

	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Location of the folder that contains $node (the node itself may be gone or not have an ID yet).
	 */
	public function locate(Node $node): ?NodeLocation {
		$key = dirname($node->getPath());
		if (array_key_exists($key, $this->locations)) {
			return $this->locations[$key];
		}

		try {
			$location = $this->locateFolder($node->getParent());
		} catch (\Throwable $e) {
			$this->logger->debug('Folder Watch: cannot locate the parent of ' . $node->getPath(), ['exception' => $e, 'app' => 'folderwatch']);
			$location = null;
		}

		if (count($this->locations) >= self::MAX_MEMO) {
			$this->locations = [];
		}
		return $this->locations[$key] = $location;
	}

	public function locateFolder(Folder $folder): ?NodeLocation {
		$folder = $this->inOwnerView($folder);

		$cache = $folder->getStorage()->getCache();
		$chain = [];
		$paths = [];
		$id = $folder->getId();
		while ($id > 0 && !isset($paths[$id]) && count($chain) < self::MAX_DEPTH) {
			$entry = $cache->get($id);
			if (!$entry) {
				break;
			}
			$chain[] = $id;
			$paths[$id] = $entry->getPath();
			$id = $entry->getParentId();
		}

		if ($chain === [] || $chain[0] !== $folder->getId()) {
			return null;
		}
		return new NodeLocation($chain[0], $chain, $paths);
	}

	public function reset(): void {
		$this->locations = [];
	}

	/**
	 * A folder reached through someone else's share is looked up again from its owner,
	 * whose storage knows the whole tree above the share point.
	 */
	private function inOwnerView(Folder $folder): Folder {
		$owner = $folder->getOwner();
		if (!$owner || preg_match('#^/([^/]+)/files(/|$)#', $folder->getPath(), $match) !== 1 || $match[1] === $owner->getUID()) {
			return $folder;
		}
		$ownerFolder = $this->rootFolder->getUserFolder($owner->getUID())->getFirstNodeById($folder->getId());
		return $ownerFolder instanceof Folder ? $ownerFolder : $folder;
	}
}
