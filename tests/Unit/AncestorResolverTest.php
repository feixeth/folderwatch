<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Service\AncestorResolver;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AncestorResolverTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private AncestorResolver $resolver;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->resolver = new AncestorResolver($this->rootFolder, $this->createMock(LoggerInterface::class));
	}

	/**
	 * @param array<int, array{string, int}> $entries id => [internal path, parent id]; unknown IDs are not in the cache
	 */
	private function cache(array $entries): ICache&MockObject {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function ($id) use ($entries) {
			if (!isset($entries[$id])) {
				return false;
			}
			$entry = $this->createMock(ICacheEntry::class);
			$entry->method('getPath')->willReturn($entries[$id][0]);
			$entry->method('getParentId')->willReturn($entries[$id][1]);
			return $entry;
		});
		return $cache;
	}

	private function folder(int $id, string $path, ?string $ownerUid, ICache $cache): Folder&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getCache')->willReturn($cache);
		$owner = null;
		if ($ownerUid !== null) {
			$owner = $this->createMock(IUser::class);
			$owner->method('getUID')->willReturn($ownerUid);
		}
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getOwner')->willReturn($owner);
		$folder->method('getStorage')->willReturn($storage);
		return $folder;
	}

	private function nodeIn(Folder $parent, string $path): Node&MockObject {
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willReturn($path);
		$node->method('getParent')->willReturn($parent);
		return $node;
	}

	/** files(10) / Docs(20) / Sub(30) in alice's home storage */
	private function aliceEntries(): array {
		return [
			30 => ['files/Docs/Sub', 20],
			20 => ['files/Docs', 10],
			10 => ['files', 5],
			5 => ['', -1],
		];
	}

	public function testOwnerViewWalksTheStorageCacheUpToTheRoot(): void {
		$folder = $this->folder(30, '/alice/files/Docs/Sub', 'alice', $this->cache($this->aliceEntries()));

		$location = $this->resolver->locateFolder($folder);

		$this->assertSame(30, $location->parentId);
		$this->assertSame([30, 20, 10, 5], $location->chain);
		$this->assertSame('files/Docs', $location->paths[20]);
	}

	public function testShareRecipientIsResolvedFromTheOwnersStorage(): void {
		// bob reaches alice's Sub folder through a share rooted at Docs: his cache stops at the share root
		$bobCache = $this->cache([30 => ['Sub', 20], 20 => ['', -1]]);
		$bobFolder = $this->folder(30, '/bob/files/Docs/Sub', 'alice', $bobCache);
		$bobFolder->expects($this->never())->method('getStorage');

		$aliceFolder = $this->folder(30, '/alice/files/Docs/Sub', 'alice', $this->cache($this->aliceEntries()));
		$aliceHome = $this->createMock(Folder::class);
		$aliceHome->method('getFirstNodeById')->with(30)->willReturn($aliceFolder);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($aliceHome);

		$location = $this->resolver->locateFolder($bobFolder);

		// the chain goes above the share point, so alice watching "/" is reachable
		$this->assertSame([30, 20, 10, 5], $location->chain);
	}

	public function testRecipientViewIsKeptWhenTheOwnerCannotBeResolved(): void {
		$bobFolder = $this->folder(30, '/bob/files/Docs/Sub', 'alice', $this->cache([30 => ['Sub', 20], 20 => ['', -1]]));
		$aliceHome = $this->createMock(Folder::class);
		$aliceHome->method('getFirstNodeById')->willReturn(null);
		$this->rootFolder->method('getUserFolder')->willReturn($aliceHome);

		$this->assertSame([30, 20], $this->resolver->locateFolder($bobFolder)->chain);
	}

	public function testFolderWithoutOwnerUsesItsOwnCache(): void {
		// group folder: the cache is walked up to the root of the mount
		$cache = $this->cache([77 => ['Reports', 70], 70 => ['', -1]]);
		$folder = $this->folder(77, '/alice/files/Team/Reports', null, $cache);
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->assertSame([77, 70], $this->resolver->locateFolder($folder)->chain);
	}

	public function testCacheMissTruncatesTheChain(): void {
		// the parent (20) is outside what this storage knows about
		$folder = $this->folder(30, '/alice/files/Docs/Sub', 'alice', $this->cache([30 => ['Sub', 20]]));
		$this->assertSame([30], $this->resolver->locateFolder($folder)->chain);
	}

	public function testUnknownFolderCannotBeLocated(): void {
		$folder = $this->folder(30, '/alice/files/Docs/Sub', 'alice', $this->cache([]));
		$this->assertNull($this->resolver->locateFolder($folder));
	}

	public function testCycleInTheCacheDoesNotLoopForever(): void {
		$folder = $this->folder(30, '/alice/files/A', 'alice', $this->cache([30 => ['files/A', 20], 20 => ['files', 30]]));
		$this->assertSame([30, 20], $this->resolver->locateFolder($folder)->chain);
	}

	public function testSiblingsShareOneLookup(): void {
		$parent = $this->folder(30, '/alice/files/Docs/Sub', 'alice', $this->cache($this->aliceEntries()));
		$first = $this->createMock(Node::class);
		$first->method('getPath')->willReturn('/alice/files/Docs/Sub/1.txt');
		$first->expects($this->once())->method('getParent')->willReturn($parent);
		$second = $this->createMock(Node::class);
		$second->method('getPath')->willReturn('/alice/files/Docs/Sub/2.txt');
		$second->expects($this->never())->method('getParent');

		$this->assertSame($this->resolver->locate($first), $this->resolver->locate($second));
	}

	public function testResetForgetsKnownLocations(): void {
		$parent = $this->folder(30, '/alice/files/Docs/Sub', 'alice', $this->cache($this->aliceEntries()));
		$node = $this->nodeIn($parent, '/alice/files/Docs/Sub/1.txt');
		$this->resolver->locate($node);
		$this->resolver->reset();

		$other = $this->createMock(Node::class);
		$other->method('getPath')->willReturn('/alice/files/Docs/Sub/2.txt');
		$other->expects($this->once())->method('getParent')->willReturn($parent);
		$this->resolver->locate($other);
	}

	public function testNodeWhoseParentCannotBeReadHasNoLocation(): void {
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willReturn('/alice/files/x');
		$node->method('getParent')->willThrowException(new \OCP\Files\NotFoundException());
		$this->assertNull($this->resolver->locate($node));
	}
}
