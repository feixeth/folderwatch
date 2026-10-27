<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Alert\AlertSender;
use OCA\FolderWatch\Alert\SendReport;
use OCA\FolderWatch\Command\AddWatch;
use OCA\FolderWatch\Command\Flush;
use OCA\FolderWatch\Command\ListWatches;
use OCA\FolderWatch\Command\RemoveWatch;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Service\WatchException;
use OCA\FolderWatch\Service\WatchService;
use OCP\AppFramework\Http;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CommandsTest extends TestCase {
	private WatchService&MockObject $service;
	private IUserManager&MockObject $users;

	protected function setUp(): void {
		$this->service = $this->createMock(WatchService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid !== 'ghost');
	}

	/** @return array<string, mixed> */
	private function described(int $id, string $user, ?string $path = '/Docs', array $overrides = []): array {
		return $overrides + [
			'id' => $id, 'fileId' => 100 + $id, 'path' => $path, 'name' => 'Docs', 'url' => 'https://nc.test/f/1', 'accessible' => $path !== null,
			'recursive' => true, 'events' => ['created', 'moved_in'], 'channels' => ['notification', 'mail'],
			'frequency' => 'immediate', 'digestHour' => 8, 'createdAt' => 1, 'lastSentAt' => null,
		];
	}

	private function lister(array $watches, array $pending = []): CommandTester {
		$mapper = $this->createMock(WatchMapper::class);
		$mapper->method('findAll')->willReturn($watches);
		$mapper->method('findByUser')->willReturnCallback(static fn (string $uid): array => array_values(array_filter($watches, static fn ($w): bool => $w->getUserId() === $uid)));
		$queue = $this->createMock(QueueItemMapper::class);
		$queue->method('getPendingStats')->willReturn($pending);
		$this->service->method('describe')->willReturnCallback(fn ($watch): array => $this->described($watch->getId(), $watch->getUserId(), $watch->getId() === 9 ? null : '/Docs', $watch->getId() === 3 ? ['frequency' => 'daily', 'digestHour' => 17] : []));
		return new CommandTester(new ListWatches($mapper, $queue, $this->service, $this->users));
	}

	public function testListShowsAllWatchesInATable(): void {
		$tester = $this->lister([WatchFactory::make(1, 'alice', 101), WatchFactory::make(3, 'bob', 103)], [1 => ['count' => 4, 'oldest' => 1, 'newest' => 2]]);
		$this->assertSame(Command::SUCCESS, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('alice', $display);
		$this->assertStringContainsString('bob', $display);
		$this->assertStringContainsString('daily at 17:00', $display);
		$this->assertMatchesRegularExpression('/\| 1\s+\| alice\s+\| \/Docs\s+\| active\s+\| yes\s+\| created,moved_in\s+\| notification,mail\s+\| immediate\s+\| 4\s+\|/', $display);
	}

	public function testListCanBeLimitedToAUser(): void {
		$tester = $this->lister([WatchFactory::make(1, 'alice', 101), WatchFactory::make(3, 'bob', 103)]);
		$tester->execute(['--user' => 'bob']);
		$this->assertStringContainsString('bob', $tester->getDisplay());
		$this->assertStringNotContainsString('alice', $tester->getDisplay());
	}

	public function testListShowsSuspendedAndUnreachableWatches(): void {
		$watch = WatchFactory::make(9, 'alice', 109);
		$watch->setInaccessibleSince(5);
		$tester = $this->lister([$watch]);
		$tester->execute([]);
		$this->assertStringContainsString('suspended', $tester->getDisplay());
		$this->assertStringContainsString('(unreachable, file ID 109)', $tester->getDisplay());
	}

	public function testListAsJson(): void {
		$tester = $this->lister([WatchFactory::make(1, 'alice', 101)], [1 => ['count' => 2, 'oldest' => 1, 'newest' => 2]]);
		$tester->execute(['--output' => 'json']);
		$rows = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(1, $rows[0]['id']);
		$this->assertSame(['created', 'moved_in'], $rows[0]['events']);
		$this->assertSame(2, $rows[0]['pending']);
	}

	public function testListWithNothingToShow(): void {
		$tester = $this->lister([]);
		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertStringContainsString('No watched folder.', $tester->getDisplay());
		$tester->execute(['--output' => 'json']);
		$this->assertSame('[]', trim($tester->getDisplay()));
	}

	public function testListRefusesAnUnknownUserAndAnUnknownFormat(): void {
		$tester = $this->lister([]);
		$this->assertSame(Command::FAILURE, $tester->execute(['--user' => 'ghost']));
		$this->assertStringContainsString('does not exist', $tester->getDisplay());
		$this->assertSame(Command::INVALID, $tester->execute(['--output' => 'xml']));
	}

	private function adder(): CommandTester {
		return new CommandTester(new AddWatch($this->service, $this->users));
	}

	public function testAddPassesTheOptionsToTheService(): void {
		$this->service->expects($this->once())->method('add')
			->with('alice', null, '/Documents/Inbox', ['recursive' => false, 'channels' => ['mail', 'notification'], 'events' => ['created', 'modified'], 'frequency' => 'daily', 'digestHour' => 17])
			->willReturn($this->described(5, 'alice', '/Documents/Inbox', ['recursive' => false, 'frequency' => 'daily']));

		$tester = $this->adder();
		$this->assertSame(Command::SUCCESS, $tester->execute([
			'user' => 'alice', 'path' => '/Documents/Inbox', '--no-recursive' => true, '--channel' => ['mail,notification'],
			'--events' => ['created,modified'], '--frequency' => 'daily', '--digest-hour' => '17',
		]));
		$this->assertStringContainsString('Watch 5 created: /Documents/Inbox for alice (without sub-folders', $tester->getDisplay());
	}

	public function testAddWithNothingButTheRequiredArgumentsLeavesTheDefaultsToTheService(): void {
		$this->service->expects($this->once())->method('add')->with('alice', null, '/Docs', [])->willReturn($this->described(1, 'alice'));
		$this->assertSame(Command::SUCCESS, $this->adder()->execute(['user' => 'alice', 'path' => '/Docs']));
	}

	public function testAddAcceptsTheChannelOptionRepeated(): void {
		$this->service->expects($this->once())->method('add')->with('alice', null, '/Docs', ['channels' => ['mail', 'notification']])->willReturn($this->described(1, 'alice'));
		$this->adder()->execute(['user' => 'alice', 'path' => '/Docs', '--channel' => ['mail', 'notification']]);
	}

	public function testAddRefusesAnUnknownUser(): void {
		$this->service->expects($this->never())->method('add');
		$tester = $this->adder();
		$this->assertSame(Command::FAILURE, $tester->execute(['user' => 'ghost', 'path' => '/Docs']));
		$this->assertStringContainsString('User "ghost" does not exist', $tester->getDisplay());
	}

	public function testAddReportsWhatTheServiceRefuses(): void {
		$this->service->method('add')->willThrowException(new WatchException('Folder not found.', Http::STATUS_NOT_FOUND));
		$tester = $this->adder();
		$this->assertSame(Command::FAILURE, $tester->execute(['user' => 'alice', 'path' => '/nope']));
		$this->assertStringContainsString('Folder not found.', $tester->getDisplay());
	}

	public function testAddRefusesADigestHourThatIsNotANumber(): void {
		$this->service->expects($this->never())->method('add');
		$this->assertSame(Command::INVALID, $this->adder()->execute(['user' => 'alice', 'path' => '/Docs', '--digest-hour' => 'noon']));
		$this->assertSame(Command::INVALID, $this->adder()->execute(['user' => 'alice', 'path' => '/Docs', '--digest-hour' => '7.5']));
	}

	public function testRemove(): void {
		$this->service->method('removeById')->willReturnCallback(static fn (int $id): bool => $id === 4);
		$tester = new CommandTester(new RemoveWatch($this->service));

		$this->assertSame(Command::SUCCESS, $tester->execute(['id' => '4']));
		$this->assertStringContainsString('Watch 4 removed.', $tester->getDisplay());
		$this->assertSame(Command::FAILURE, $tester->execute(['id' => '5']));
		$this->assertStringContainsString('There is no watch 5', $tester->getDisplay());
		$this->assertSame(Command::INVALID, $tester->execute(['id' => 'abc']));
		$this->assertSame(Command::INVALID, $tester->execute(['id' => '-1']));
	}

	private function flusher(SendReport $report): CommandTester {
		$sender = $this->createMock(AlertSender::class);
		$sender->expects($this->once())->method('process')->with(true)->willReturn($report);
		return new CommandTester(new Flush($sender));
	}

	public function testFlushForcesTheSendingAndSaysWhatHappened(): void {
		$report = new SendReport();
		$report->sent = 3;
		$report->capped = 1;
		$report->dropped = 5;
		$report->mailSkipped = 2;
		$tester = $this->flusher($report);

		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$display = $tester->getDisplay();
		$this->assertStringContainsString('3 alert(s) sent.', $display);
		$this->assertStringContainsString('1 held back by the hourly limit', $display);
		$this->assertStringContainsString('5 queued item(s) dropped', $display);
		$this->assertStringContainsString('2 e-mail(s) skipped', $display);
	}

	public function testFlushStaysQuietWhenThereIsNothingToReport(): void {
		$tester = $this->flusher(new SendReport());
		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertSame("0 alert(s) sent.\n", $tester->getDisplay());
	}

	public function testFlushFailsWhenSomethingCouldNotBeDelivered(): void {
		$report = new SendReport();
		$report->failed = 1;
		$tester = $this->flusher($report);
		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertStringContainsString('could not be delivered', $tester->getDisplay());
	}
}
