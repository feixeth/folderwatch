<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\SendLogMapper;
use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Listener\UserDeletedListener;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserDeletedListenerTest extends TestCase {
	private function listener(WatchMapper $watches, QueueItemMapper $queue, SendLogMapper $log): UserDeletedListener {
		return new UserDeletedListener($watches, $queue, $log, $this->createMock(LoggerInterface::class));
	}

	public function testEverythingOfTheUserIsRemoved(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$watches = $this->createMock(WatchMapper::class);
		$watches->expects($this->once())->method('deleteByUser')->with('alice');
		$queue = $this->createMock(QueueItemMapper::class);
		$queue->expects($this->once())->method('deleteByUser')->with('alice');
		$log = $this->createMock(SendLogMapper::class);
		$log->expects($this->once())->method('deleteByUser')->with('alice');

		$this->listener($watches, $queue, $log)->handle(new UserDeletedEvent($user));
	}

	public function testAFailureDoesNotBreakTheDeletionOfTheAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$queue = $this->createMock(QueueItemMapper::class);
		$queue->method('deleteByUser')->willThrowException(new \RuntimeException('db down'));

		$this->listener($this->createMock(WatchMapper::class), $queue, $this->createMock(SendLogMapper::class))->handle(new UserDeletedEvent($user));
		$this->addToAssertionCount(1);
	}

	public function testOtherEventsAreIgnored(): void {
		$watches = $this->createMock(WatchMapper::class);
		$watches->expects($this->never())->method('deleteByUser');
		$this->listener($watches, $this->createMock(QueueItemMapper::class), $this->createMock(SendLogMapper::class))->handle(new class() extends Event {
		});
	}
}
