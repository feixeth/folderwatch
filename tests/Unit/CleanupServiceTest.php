<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Alert\BatchVerifier;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Service\CleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Config\IUserMountCache;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupServiceTest extends TestCase {
	private const NOW = 1_790_000_000;
	private const FOLDER = ['id' => 7, 'name' => 'Docs', 'path' => '/Docs', 'url' => 'https://nc.test/f/7'];

	private WatchMapper&MockObject $watches;
	private QueueItemMapper&MockObject $queue;
	private BatchVerifier&MockObject $verifier;
	private IUserManager&MockObject $users;
	private IUserMountCache&MockObject $mounts;
	private FakeAppConfig $config;
	private CleanupService $service;
	/** @var list<int> */
	private array $deleted = [];

	protected function setUp(): void {
		$this->watches = $this->createMock(WatchMapper::class);
		$this->watches->method('delete')->willReturnCallback(function (Watch $watch): Watch {
			$this->deleted[] = $watch->getId();
			return $watch;
		});
		$this->queue = $this->createMock(QueueItemMapper::class);
		$this->verifier = $this->createMock(BatchVerifier::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('userExists')->willReturn(true);
		$this->mounts = $this->createMock(IUserMountCache::class);
		$this->config = FakeAppConfig::create($this);
		$this->service = $this->build();
	}

	private function build(?ITimeFactory $time = null): CleanupService {
		if ($time === null) {
			$time = $this->createMock(ITimeFactory::class);
			$time->method('getTime')->willReturn(self::NOW);
		}
		return new CleanupService($this->watches, $this->queue, $this->verifier, $this->users, $this->mounts, $this->config->config, $time, $this->createMock(LoggerInterface::class));
	}

	private function watch(int $id, ?int $unreachableFor = null, string $user = 'alice'): Watch {
		$watch = WatchFactory::make($id, $user, 100 + $id);
		$watch->setInaccessibleSince($unreachableFor === null ? null : self::NOW - $unreachableFor);
		return $watch;
	}

	/** @param list<Watch> $watches */
	private function stored(array $watches): void {
		$this->watches->method('findAfter')->willReturnCallback(static fn (int $after, int $limit): array => array_slice(array_values(array_filter($watches, static fn (Watch $w): bool => $w->getId() > $after)), 0, $limit));
	}

	private function reachable(bool $reachable): void {
		$this->verifier->method('getFolder')->willReturn($reachable ? self::FOLDER : null);
	}

	public function testAReachableWatchIsLeftAlone(): void {
		$this->stored([$this->watch(1)]);
		$this->reachable(true);
		$this->watches->expects($this->never())->method('update');

		$report = $this->service->run();
		$this->assertSame([1, 0, 0], [$report->checked, $report->suspended, $report->revived]);
	}

	public function testAWatchWhoseFolderCameBackIsRevived(): void {
		$watch = $this->watch(1, 3 * 86400);
		$this->stored([$watch]);
		$this->reachable(true);
		$this->watches->expects($this->once())->method('update')->with($watch);

		$report = $this->service->run();
		$this->assertSame(1, $report->revived);
		$this->assertNull($watch->getInaccessibleSince());
		$this->assertSame([], $this->deleted);
	}

	public function testAFolderThatDisappearedSuspendsItsWatchWithoutRemovingIt(): void {
		$watch = $this->watch(1);
		$this->stored([$watch]);
		$this->reachable(false);

		$report = $this->service->run();
		$this->assertSame(1, $report->suspended);
		$this->assertSame(self::NOW, $watch->getInaccessibleSince());
		$this->assertSame([], $this->deleted);
		$this->mounts->expects($this->never())->method('getMountsForFileId');
	}

	public function testTheStartOfTheSuspensionIsNotMovedByLaterRuns(): void {
		$watch = $this->watch(1, 3600);
		$this->stored([$watch]);
		$this->reachable(false);
		$this->watches->expects($this->never())->method('update');

		$this->service->run();
		$this->assertSame(self::NOW - 3600, $watch->getInaccessibleSince());
		$this->assertSame([], $this->deleted);
	}

	public function testAFolderInTheTrashIsKeptForWeeks(): void {
		// the file ID is still in the file cache, so some mount knows it
		$this->stored([$this->watch(1, 20 * 86400)]);
		$this->reachable(false);
		$this->mounts->method('getMountsForFileId')->willReturn([$this->createMock(\OCP\Files\Config\ICachedMountFileInfo::class)]);

		$report = $this->service->run();
		$this->assertSame([], $this->deleted);
		$this->assertSame(0, $report->purgedDeleted + $report->purgedExpired);
	}

	public function testAFolderDeletedForGoodIsRemovedAfterTheShortGrace(): void {
		$this->stored([$this->watch(1, CleanupService::DELETED_GRACE_SECONDS + 1)]);
		$this->reachable(false);
		$this->mounts->method('getMountsForFileId')->with(101)->willReturn([]);
		$this->queue->expects($this->once())->method('deleteByWatch')->with(1);

		$report = $this->service->run();
		$this->assertSame([1], $this->deleted);
		$this->assertSame(1, $report->purgedDeleted);
	}

	public function testAFolderThatLooksDeletedIsStillGivenTheShortGrace(): void {
		// the answer "no mount knows this file" can come from a mount that was never set up
		$this->stored([$this->watch(1, 3600)]);
		$this->reachable(false);
		$this->mounts->method('getMountsForFileId')->willReturn([]);

		$this->service->run();
		$this->assertSame([], $this->deleted);
	}

	public function testAWatchUnreachableForTooLongIsRemovedEvenIfTheFolderExists(): void {
		$this->stored([$this->watch(1, CleanupService::EXPIRY_SECONDS)]);
		$this->reachable(false);
		$this->mounts->method('getMountsForFileId')->willReturn([$this->createMock(\OCP\Files\Config\ICachedMountFileInfo::class)]);

		$report = $this->service->run();
		$this->assertSame([1], $this->deleted);
		$this->assertSame(1, $report->purgedExpired);
	}

	public function testTheWatchesOfAMissingUserAreRemoved(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid !== 'ghost');
		$this->stored([$this->watch(1, null, 'ghost'), $this->watch(2)]);
		$this->reachable(true);
		$service = new CleanupService($this->watches, $this->queue, $this->verifier, $users, $this->mounts, $this->config->config, $this->timeAt(self::NOW), $this->createMock(LoggerInterface::class));

		$report = $service->run();
		$this->assertSame([1], $this->deleted);
		$this->assertSame(1, $report->purgedOrphanedUser);
	}

	public function testOneBrokenWatchDoesNotStopTheOthers(): void {
		$this->stored([$this->watch(1), $this->watch(2, CleanupService::EXPIRY_SECONDS + 5)]);
		$this->verifier->method('getFolder')->willReturnCallback(static function (Watch $watch): ?array {
			if ($watch->getId() === 1) {
				throw new \RuntimeException('storage offline');
			}
			return null;
		});
		$this->mounts->method('getMountsForFileId')->willReturn([$this->createMock(\OCP\Files\Config\ICachedMountFileInfo::class)]);

		$report = $this->service->run();
		$this->assertSame(2, $report->checked);
		$this->assertSame([2], $this->deleted);
	}

	public function testOrphanedQueueRowsAreRemoved(): void {
		$this->stored([]);
		$this->queue->expects($this->once())->method('deleteOrphans')->willReturn(12);
		$this->assertSame(12, $this->service->run()->queueOrphans);
	}

	public function testAllWatchesAreCheckedInSlicesAndTheCursorStartsOverAtTheEnd(): void {
		$all = array_map(fn (int $id): Watch => $this->watch($id), range(1, 450));
		$this->stored($all);
		$this->reachable(true);

		$report = $this->service->run();

		$this->assertSame(450, $report->checked);
		$this->assertSame(0, $this->config->values['folderwatch/cleanup_cursor']);
	}

	public function testARunThatRanOutOfTimeContinuesWhereItStopped(): void {
		$all = array_map(fn (int $id): Watch => $this->watch($id), range(1, 450));
		$this->stored($all);
		$this->reachable(true);
		// the clock jumps 10 minutes after each look at it: the budget is gone after the first slice
		$clock = self::NOW;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static function () use (&$clock): int {
			$clock += 600;
			return $clock;
		});
		$service = $this->build($time);

		$first = $service->run(300);
		$this->assertSame(200, $first->checked);
		$this->assertSame(200, $this->config->values['folderwatch/cleanup_cursor']);

		$second = $service->run(1_000_000);
		$this->assertSame(250, $second->checked);
		$this->assertSame(0, $this->config->values['folderwatch/cleanup_cursor']);
	}

	private function timeAt(int $now): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		return $time;
	}
}
