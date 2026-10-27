<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Db\QueueItem;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Service\EventBuffer;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EventBufferTest extends TestCase {
	private QueueItemMapper&MockObject $mapper;
	private EventBuffer $buffer;

	protected function setUp(): void {
		$this->mapper = $this->createMock(QueueItemMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$this->buffer = new EventBuffer($this->mapper, $time, $this->createMock(LoggerInterface::class), false);
	}

	public static function merges(): array {
		$c = WatchOptions::EVENT_CREATED;
		$m = WatchOptions::EVENT_MODIFIED;
		$d = WatchOptions::EVENT_DELETED;
		$i = WatchOptions::EVENT_MOVED_IN;
		return [
			'created then modified stays created' => [$c, $m, $c],
			'moved in then modified stays moved in' => [$i, $m, $i],
			'created then deleted cancels out' => [$c, $d, null],
			'moved in then deleted cancels out' => [$i, $d, null],
			'modified then deleted is deleted' => [$m, $d, $d],
			'deleted then created is a modification' => [$d, $c, $m],
			'deleted then moved in is a modification' => [$d, $i, $m],
			'modified twice' => [$m, $m, $m],
			'modified then created' => [$m, $c, $c],
			'created twice' => [$c, $c, $c],
		];
	}

	#[DataProvider('merges')]
	public function testMerge(int $previous, int $next, ?int $expected): void {
		$this->assertSame($expected, EventBuffer::merge($previous, $next));
	}

	public function testSameFileForSameWatchIsQueuedOnce(): void {
		$watch = WatchFactory::make(1, 'alice', 10);
		$this->buffer->add($watch, 500, WatchOptions::EVENT_CREATED, 'bob', 'a.txt');
		$this->buffer->add($watch, 500, WatchOptions::EVENT_MODIFIED, 'bob', 'a.txt');

		$this->mapper->expects($this->once())->method('insertBatch')->with($this->callback(function (array $items): bool {
			$this->assertCount(1, $items);
			$this->assertSame(WatchOptions::EVENT_CREATED, $items[0]->getEvent());
			$this->assertSame('alice', $items[0]->getUserId());
			$this->assertSame('bob', $items[0]->getActorUid());
			$this->assertSame(1000, $items[0]->getCreatedAt());
			return true;
		}));
		$this->buffer->flush();
	}

	public function testSameFileForTwoWatchesIsQueuedTwice(): void {
		$this->buffer->add(WatchFactory::make(1, 'alice', 10), 500, WatchOptions::EVENT_CREATED, null, 'a.txt');
		$this->buffer->add(WatchFactory::make(2, 'carol', 10), 500, WatchOptions::EVENT_CREATED, null, 'a.txt');
		$this->assertSame(2, $this->buffer->count());
	}

	public function testCreatedThenDeletedLeavesNothingToWrite(): void {
		$watch = WatchFactory::make(1, 'alice', 10);
		$this->buffer->add($watch, 500, WatchOptions::EVENT_CREATED, null, 'tmp.txt');
		$this->buffer->add($watch, 500, WatchOptions::EVENT_DELETED, null, 'tmp.txt');
		$this->mapper->expects($this->never())->method('insertBatch');
		$this->buffer->flush();
		$this->assertSame(0, $this->buffer->count());
	}

	public function testFiveHundredFilesAreWrittenInOneCall(): void {
		$watch = WatchFactory::make(1, 'alice', 10);
		for ($i = 1; $i <= 500; $i++) {
			$this->buffer->add($watch, $i, WatchOptions::EVENT_CREATED, 'bob', "file-$i.txt");
		}
		$this->mapper->expects($this->once())->method('insertBatch')
			->with($this->callback(static fn (array $items): bool => count($items) === 500));
		$this->buffer->flush();
	}

	public function testFlushEmptiesTheBufferEvenWhenTheWriteFails(): void {
		$this->buffer->add(WatchFactory::make(1, 'alice', 10), 500, WatchOptions::EVENT_CREATED, null, 'a.txt');
		$this->mapper->expects($this->once())->method('insertBatch')->willThrowException(new \RuntimeException('db down'));
		$this->buffer->flush();
		$this->assertSame(0, $this->buffer->count());
	}

	public function testPathHintIsTruncated(): void {
		$this->buffer->add(WatchFactory::make(1, 'alice', 10), 500, WatchOptions::EVENT_CREATED, null, str_repeat('é', 2000));
		$this->mapper->expects($this->once())->method('insertBatch')->with($this->callback(function (array $items): bool {
			/** @var list<QueueItem> $items */
			$this->assertSame(1024, mb_strlen($items[0]->getPathHint()));
			return true;
		}));
		$this->buffer->flush();
	}

	public function testCreatedFilesAreForgottenOnceTheBufferIsFlushed(): void {
		$this->buffer->markCreated(5);
		$this->buffer->flush();
		$this->assertFalse($this->buffer->wasCreated(5));
	}

	public function testCreatedFilesAreRemembered(): void {
		$this->assertFalse($this->buffer->wasCreated(5));
		$this->buffer->markCreated(5);
		$this->assertTrue($this->buffer->wasCreated(5));
	}

	public function testTheLinksStayWithTheQueuedEvent(): void {
		$this->buffer->add(WatchFactory::make(1, 'alice', 10), 500, WatchOptions::EVENT_CREATED, 'bob', 'a.txt', 'https://nc.test/f/500', 'https://nc.test/f/10');
		$this->mapper->expects($this->once())->method('insertBatch')->with($this->callback(function (array $items): bool {
			$this->assertSame('https://nc.test/f/500', $items[0]->getUrl());
			$this->assertSame('https://nc.test/f/10', $items[0]->getFolderUrl());
			return true;
		}));
		$this->buffer->flush();
	}

	public function testALaterEventWithoutLinksDoesNotEraseThem(): void {
		$watch = WatchFactory::make(1, 'alice', 10);
		$this->buffer->add($watch, 500, WatchOptions::EVENT_CREATED, 'bob', 'a.txt', 'https://nc.test/f/500', 'https://nc.test/f/10');
		$this->buffer->add($watch, 500, WatchOptions::EVENT_MODIFIED, 'bob', 'a.txt');
		$this->mapper->expects($this->once())->method('insertBatch')->with($this->callback(function (array $items): bool {
			$this->assertSame(WatchOptions::EVENT_CREATED, $items[0]->getEvent());
			$this->assertSame('https://nc.test/f/500', $items[0]->getUrl());
			$this->assertSame('https://nc.test/f/10', $items[0]->getFolderUrl());
			return true;
		}));
		$this->buffer->flush();
	}

	public function testAnEventMayHaveNoLinkAtAll(): void {
		$this->buffer->add(WatchFactory::make(1, 'alice', 10), 500, WatchOptions::EVENT_DELETED, 'bob', 'a.txt');
		$this->mapper->expects($this->once())->method('insertBatch')->with($this->callback(function (array $items): bool {
			$this->assertNull($items[0]->getUrl());
			$this->assertNull($items[0]->getFolderUrl());
			return true;
		}));
		$this->buffer->flush();
	}
}
