<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Alert\Aggregator;
use OCA\FolderWatch\Db\QueueItem;
use OCA\FolderWatch\Db\WatchOptions;
use PHPUnit\Framework\TestCase;

class AggregatorTest extends TestCase {
	private function item(int $id, int $fileId, int $event, ?string $actor, string $hint, int $time, ?string $url = null): QueueItem {
		$item = new QueueItem();
		$item->setId($id);
		$item->setWatchId(1);
		$item->setUserId('alice');
		$item->setFileId($fileId);
		$item->setEvent($event);
		$item->setActorUid($actor);
		$item->setPathHint($hint);
		$item->setCreatedAt($time);
		$item->setUrl($url);
		return $item;
	}

	public function testOneEntryPerFileOldestFirst(): void {
		$entries = (new Aggregator())->aggregate([
			$this->item(2, 20, WatchOptions::EVENT_CREATED, 'bob', 'b.txt', 200),
			$this->item(1, 10, WatchOptions::EVENT_CREATED, 'bob', 'a.txt', 100),
		]);
		$this->assertSame(['a.txt', 'b.txt'], array_map(static fn ($e) => $e->pathHint, $entries));
	}

	public function testCreatedThenModifiedStaysCreatedByTheCreator(): void {
		$entries = (new Aggregator())->aggregate([
			$this->item(1, 10, WatchOptions::EVENT_CREATED, 'bob', 'a.txt', 100),
			$this->item(2, 10, WatchOptions::EVENT_MODIFIED, 'carol', 'a.txt', 300),
		]);
		$this->assertCount(1, $entries);
		$this->assertSame(WatchOptions::EVENT_CREATED, $entries[0]->event);
		$this->assertSame('bob', $entries[0]->actorUid);
		$this->assertSame(100, $entries[0]->time);
	}

	public function testCreatedThenDeletedDisappears(): void {
		$entries = (new Aggregator())->aggregate([
			$this->item(1, 10, WatchOptions::EVENT_CREATED, 'bob', 'a.txt', 100),
			$this->item(2, 10, WatchOptions::EVENT_DELETED, 'bob', 'a.txt', 200),
			$this->item(3, 20, WatchOptions::EVENT_CREATED, 'bob', 'b.txt', 250),
		]);
		$this->assertSame(['b.txt'], array_map(static fn ($e) => $e->pathHint, $entries));
	}

	public function testDeletedThenRecreatedIsAModification(): void {
		$entries = (new Aggregator())->aggregate([
			$this->item(1, 10, WatchOptions::EVENT_DELETED, 'bob', 'a.txt', 100),
			$this->item(2, 10, WatchOptions::EVENT_CREATED, 'carol', 'a.txt', 200),
		]);
		$this->assertSame(WatchOptions::EVENT_MODIFIED, $entries[0]->event);
		$this->assertSame('carol', $entries[0]->actorUid);
	}

	public function testMovedFileKeepsItsLatestPath(): void {
		$entries = (new Aggregator())->aggregate([
			$this->item(1, 10, WatchOptions::EVENT_CREATED, 'bob', 'a.txt', 100),
			$this->item(2, 10, WatchOptions::EVENT_MODIFIED, 'bob', 'Sub/a.txt', 200),
		]);
		$this->assertSame('Sub/a.txt', $entries[0]->pathHint);
		$this->assertSame('a.txt', $entries[0]->getName());
	}

	public function testNothingInNothingOut(): void {
		$this->assertSame([], (new Aggregator())->aggregate([]));
	}

	public function testTheStoredLinkOfAFileTravelsWithItsEntry(): void {
		$entries = (new Aggregator())->aggregate([
			$this->item(1, 10, WatchOptions::EVENT_CREATED, 'bob', 'a.txt', 100, 'https://nc.test/f/10'),
			$this->item(2, 10, WatchOptions::EVENT_MODIFIED, 'bob', 'a.txt', 200),
			$this->item(3, 20, WatchOptions::EVENT_CREATED, 'bob', 'b.txt', 300),
		]);
		$this->assertSame(['https://nc.test/f/10', null], array_map(static fn ($e) => $e->url, $entries));
	}
}
