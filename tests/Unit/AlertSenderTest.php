<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use DateTimeZone;
use OCA\FolderWatch\Alert\Aggregator;
use OCA\FolderWatch\Alert\AlertEntry;
use OCA\FolderWatch\Alert\AlertSender;
use OCA\FolderWatch\Alert\AlertSummary;
use OCA\FolderWatch\Alert\BatchVerifier;
use OCA\FolderWatch\Alert\MailChannel;
use OCA\FolderWatch\Alert\NotificationChannel;
use OCA\FolderWatch\Db\QueueItem;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\SendLogMapper;
use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Service\AppSettings;
use OCA\FolderWatch\Service\LinkResolver;
use OCA\FolderWatch\Service\MailPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AlertSenderTest extends TestCase {
	private const NOW = 1_790_000_000;
	private const FOLDER = ['id' => 7, 'name' => 'Docs', 'path' => '/Docs'];

	private QueueItemMapper&MockObject $queue;
	private WatchMapper&MockObject $watches;
	private SendLogMapper&MockObject $sendLog;
	private BatchVerifier&MockObject $verifier;
	private NotificationChannel&MockObject $notification;
	private MailChannel&MockObject $mail;
	private AppSettings&MockObject $settings;
	private MailPolicy&MockObject $mailPolicy;
	private LinkResolver&MockObject $links;
	private IUserManager&MockObject $users;
	private AlertSender $sender;
	/** @var array<int, Watch> */
	private array $watchRows = [];

	protected function setUp(): void {
		$this->queue = $this->createMock(QueueItemMapper::class);
		$this->watches = $this->createMock(WatchMapper::class);
		$this->watches->method('find')->willReturnCallback(fn (int $id): ?Watch => $this->watchRows[$id] ?? null);
		$this->sendLog = $this->createMock(SendLogMapper::class);
		$this->verifier = $this->createMock(BatchVerifier::class);
		$this->verifier->method('getFolder')->willReturn(self::FOLDER);
		$this->verifier->method('filterReachable')->willReturnCallback(static fn (Watch $w, array $entries): array => $entries);
		$this->notification = $this->createMock(NotificationChannel::class);
		$this->mail = $this->createMock(MailChannel::class);
		$this->mail->method('send')->willReturn(true);
		$this->settings = $this->createMock(AppSettings::class);
		$this->settings->method('getMaxSendsPerHour')->willReturn(20);
		$this->mailPolicy = $this->createMock(MailPolicy::class);
		$this->mailPolicy->method('isAllowed')->willReturn(true);
		$this->links = $this->createMock(LinkResolver::class);
		$this->links->method('fileUrl')->willReturnCallback(static fn (int $id): string => "https://nc.test/f/$id");

		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturn($user);
		$this->users->method('getDisplayName')->willReturnCallback(static fn (string $uid): string => ucfirst($uid));

		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('UTC'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->sender = new AlertSender(
			$this->queue,
			$this->watches,
			$this->sendLog,
			new Aggregator(),
			$this->verifier,
			$this->notification,
			$this->mail,
			$this->settings,
			$this->mailPolicy,
			$this->links,
			$this->users,
			$zone,
			$time,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function watch(int $id, string $user = 'alice', int $channels = 3, string $frequency = 'immediate'): Watch {
		$watch = WatchFactory::make($id, $user, 7);
		$watch->setChannels($channels);
		$watch->setFrequency($frequency);
		$this->watchRows[$id] = $watch;
		return $watch;
	}

	/**
	 * @param int $age seconds since the rows were queued
	 * @return list<QueueItem>
	 */
	private function pending(int $watchId, int $count = 2, int $age = 300, bool $urls = false): array {
		$items = [];
		for ($i = 1; $i <= $count; $i++) {
			$item = new QueueItem();
			$item->setId($watchId * 100 + $i);
			$item->setWatchId($watchId);
			$item->setUserId('alice');
			$item->setFileId(1000 + $i);
			$item->setEvent(WatchOptions::EVENT_CREATED);
			$item->setActorUid('bob');
			$item->setPathHint("f$i.txt");
			$item->setCreatedAt(self::NOW - $age);
			$item->setUrl($urls ? 'https://stored.test/f/' . (1000 + $i) : null);
			$item->setFolderUrl($urls ? 'https://stored.test/f/7' : null);
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * @param array<int, list<QueueItem>> $byWatch
	 */
	private function queued(array $byWatch): void {
		$stats = [];
		foreach ($byWatch as $watchId => $items) {
			$times = array_map(static fn (QueueItem $i): int => $i->getCreatedAt(), $items);
			$stats[$watchId] = ['count' => count($items), 'oldest' => min($times), 'newest' => max($times)];
		}
		$this->queue->method('getPendingStats')->willReturn($stats);
		$this->queue->method('findByWatch')->willReturnCallback(static fn (int $id): array => $byWatch[$id] ?? []);
	}

	public function testDueBatchGoesOutOnBothChannelsAndIsConsumed(): void {
		$watch = $this->watch(1);
		$this->queued([1 => $this->pending(1)]);

		$this->notification->expects($this->once())->method('send')
			->with($watch, $this->callback(function (AlertSummary $summary): bool {
				$this->assertSame(2, $summary->total);
				$this->assertSame(['bob'], array_column($summary->actors, 'id'));
				$this->assertSame('Bob', $summary->actors[0]['name']);
				return true;
			}), self::NOW);
		$this->mail->expects($this->once())->method('send')
			->with($watch, $this->anything(), $this->callback(static fn (array $entries): bool => count($entries) === 2), self::NOW);
		$this->queue->expects($this->once())->method('deleteByIds')->with([101, 102]);
		$this->sendLog->expects($this->once())->method('record')->with('alice', self::NOW);
		$this->watches->expects($this->once())->method('update')->with($this->callback(static fn (Watch $w): bool => $w->getLastSentAt() === self::NOW));

		$report = $this->sender->process();
		$this->assertSame(1, $report->sent);
	}

	public function testBatchWhoseMomentHasNotComeIsLeftAlone(): void {
		$this->watch(1);
		$this->queued([1 => $this->pending(1, 2, 30)]);

		$this->notification->expects($this->never())->method('send');
		$this->queue->expects($this->never())->method('deleteByIds');

		$report = $this->sender->process();
		$this->assertSame(1, $report->waiting);
		$this->assertSame(0, $report->sent);
	}

	public function testForceIgnoresTheSchedule(): void {
		$this->watch(1);
		$this->queued([1 => $this->pending(1, 2, 5)]);

		$this->notification->expects($this->once())->method('send');
		$this->assertSame(1, $this->sender->process(true)->sent);
	}

	public function testHourlyCapPostponesInsteadOfDropping(): void {
		$this->watch(1);
		$settings = $this->createMock(AppSettings::class);
		$settings->method('getMaxSendsPerHour')->willReturn(3);
		$this->sendLog->method('countSince')->willReturn(3);
		$this->queued([1 => $this->pending(1)]);
		$sender = $this->withSettings($settings);

		$this->notification->expects($this->never())->method('send');
		$this->queue->expects($this->never())->method('deleteByIds');

		$report = $sender->process();
		$this->assertSame(1, $report->capped);
	}

	public function testCapIsSharedByTheWatchesOfOneUserWithinARun(): void {
		$this->watch(1);
		$this->watch(2);
		$settings = $this->createMock(AppSettings::class);
		$settings->method('getMaxSendsPerHour')->willReturn(1);
		$this->sendLog->method('countSince')->willReturn(0);
		$this->queued([1 => $this->pending(1, 1, 400), 2 => $this->pending(2, 1, 300)]);
		$sender = $this->withSettings($settings);

		$this->notification->expects($this->once())->method('send');
		$report = $sender->process();
		$this->assertSame([1, 1], [$report->sent, $report->capped]);
	}

	public function testCapOfZeroMeansNoLimit(): void {
		$this->watch(1);
		$settings = $this->createMock(AppSettings::class);
		$settings->method('getMaxSendsPerHour')->willReturn(0);
		$this->sendLog->expects($this->never())->method('countSince');
		$this->queued([1 => $this->pending(1)]);

		$this->assertSame(1, $this->withSettings($settings)->process()->sent);
	}

	public function testWatchOfAnotherUserIsNotAffectedByTheCap(): void {
		$this->watch(1, 'alice');
		$this->watch(2, 'carol');
		$settings = $this->createMock(AppSettings::class);
		$settings->method('getMaxSendsPerHour')->willReturn(1);
		$this->sendLog->method('countSince')->willReturnCallback(static fn (string $uid): int => $uid === 'alice' ? 1 : 0);
		$this->queued([1 => $this->pending(1), 2 => $this->pending(2)]);

		$report = $this->withSettings($settings)->process();
		$this->assertSame([1, 1], [$report->sent, $report->capped]);
	}

	public function testUnreachableFolderSuspendsTheWatchAndDropsItsRows(): void {
		$watch = $this->watch(1);
		$this->queued([1 => $this->pending(1)]);
		$verifier = $this->createMock(BatchVerifier::class);
		$verifier->method('getFolder')->willReturn(null);
		$sender = $this->withVerifier($verifier);

		$this->notification->expects($this->never())->method('send');
		$this->queue->expects($this->once())->method('deleteByWatch')->with(1);
		$this->watches->expects($this->once())->method('update')
			->with($this->callback(static fn (Watch $w): bool => $w->getInaccessibleSince() === self::NOW));

		$report = $sender->process();
		$this->assertSame(2, $report->dropped);
		$this->assertSame(self::NOW, $watch->getInaccessibleSince());
	}

	public function testSuspensionStartIsNotMovedWhileTheFolderStaysUnreachable(): void {
		$watch = $this->watch(1);
		$watch->setInaccessibleSince(self::NOW - 1000);
		$this->queued([1 => $this->pending(1)]);
		$verifier = $this->createMock(BatchVerifier::class);
		$verifier->method('getFolder')->willReturn(null);

		$this->watches->expects($this->never())->method('update');
		$this->withVerifier($verifier)->process();
		$this->assertSame(self::NOW - 1000, $watch->getInaccessibleSince());
	}

	public function testRestoredFolderRevivesTheWatch(): void {
		$watch = $this->watch(1);
		$watch->setInaccessibleSince(self::NOW - 1000);
		$this->queued([1 => $this->pending(1)]);

		$this->sender->process();
		$this->assertNull($watch->getInaccessibleSince());
	}

	public function testMailSwitchedOffByTheAdminLeavesTheNotification(): void {
		$this->watch(1);
		$policy = $this->createMock(MailPolicy::class);
		$policy->method('isAllowed')->willReturn(false);
		$this->queued([1 => $this->pending(1)]);

		$this->notification->expects($this->once())->method('send');
		$this->mail->expects($this->never())->method('send');
		$this->assertSame(1, $this->withMailPolicy($policy)->process()->sent);
	}

	public function testMailOnlyWatchIsDroppedWhenTheAdminSwitchedMailOff(): void {
		$this->watch(1, 'alice', WatchOptions::CHANNEL_MAIL);
		$policy = $this->createMock(MailPolicy::class);
		$policy->method('isAllowed')->willReturn(false);
		$this->queued([1 => $this->pending(1)]);

		$this->notification->expects($this->never())->method('send');
		$this->queue->expects($this->once())->method('deleteByIds');
		$this->sendLog->expects($this->never())->method('record');
		$report = $this->withMailPolicy($policy)->process();
		$this->assertSame([0, 2], [$report->sent, $report->dropped]);
	}

	public function testUserWithoutAnAddressStillGetsTheNotification(): void {
		$this->watch(1);
		$mail = $this->createMock(MailChannel::class);
		$mail->method('send')->willReturn(false);
		$this->queued([1 => $this->pending(1)]);

		$this->notification->expects($this->once())->method('send');
		$this->queue->expects($this->once())->method('deleteByIds');
		$report = $this->withMail($mail)->process();
		$this->assertSame([1, 1], [$report->sent, $report->mailSkipped]);
	}

	public function testMailOnlyWatchOfAUserWithoutAddressIsConsumedSilently(): void {
		$this->watch(1, 'alice', WatchOptions::CHANNEL_MAIL);
		$mail = $this->createMock(MailChannel::class);
		$mail->method('send')->willReturn(false);
		$this->queued([1 => $this->pending(1)]);

		$this->notification->expects($this->never())->method('send');
		$this->queue->expects($this->once())->method('deleteByIds');
		$this->sendLog->expects($this->never())->method('record');
		$report = $this->withMail($mail)->process();
		$this->assertSame([0, 0, 1], [$report->sent, $report->failed, $report->mailSkipped]);
	}

	public function testOneFailingChannelDoesNotBlockTheOther(): void {
		$this->watch(1);
		$this->notification->method('send')->willThrowException(new \RuntimeException('push down'));
		$this->queued([1 => $this->pending(1)]);

		$this->mail->expects($this->once())->method('send');
		$this->queue->expects($this->once())->method('deleteByIds');
		$report = $this->sender->process();
		$this->assertSame([1, 0], [$report->sent, $report->failed]);
	}

	public function testRowsStayQueuedWhenEveryChannelFails(): void {
		$this->watch(1);
		$this->notification->method('send')->willThrowException(new \RuntimeException('push down'));
		$mail = $this->createMock(MailChannel::class);
		$mail->method('send')->willThrowException(new \RuntimeException('smtp down'));
		$this->queued([1 => $this->pending(1)]);

		$this->queue->expects($this->never())->method('deleteByIds');
		$this->sendLog->expects($this->never())->method('record');
		$report = $this->withMail($mail)->process();
		$this->assertSame([0, 1], [$report->sent, $report->failed]);
	}

	public function testEntriesThatVanishedLeaveNothingToSend(): void {
		$this->watch(1);
		$this->queued([1 => $this->pending(1)]);
		$verifier = $this->createMock(BatchVerifier::class);
		$verifier->method('getFolder')->willReturn(self::FOLDER);
		$verifier->method('filterReachable')->willReturn([]);

		$this->notification->expects($this->never())->method('send');
		$this->queue->expects($this->once())->method('deleteByIds')->with([101, 102]);
		$this->assertSame(2, $this->withVerifier($verifier)->process()->dropped);
	}

	public function testRowsOfADeletedWatchAreRemoved(): void {
		$this->queued([9 => $this->pending(9)]);
		$this->queue->expects($this->once())->method('deleteByWatch')->with(9);
		$this->notification->expects($this->never())->method('send');
		$this->sender->process();
	}

	public function testRowsOfADisabledAccountAreRemoved(): void {
		$this->watch(1);
		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($disabled);
		$this->queued([1 => $this->pending(1)]);

		$this->queue->expects($this->once())->method('deleteByWatch')->with(1);
		$this->notification->expects($this->never())->method('send');
		$this->withUsers($users)->process();
	}

	public function testOldRowsAreAlwaysPurged(): void {
		$this->queue->method('getPendingStats')->willReturn([]);
		$this->queue->expects($this->once())->method('deleteOlderThan')->with(self::NOW - 7 * 86400)->willReturn(4);
		$this->sendLog->expects($this->once())->method('deleteOlderThan')->with(self::NOW - 86400);
		$this->assertSame(4, $this->sender->process()->purged);
	}

	public function testAFailureOnOneWatchDoesNotStopTheOthers(): void {
		$this->watch(1, 'alice');
		$this->watch(2, 'carol');
		$this->queued([1 => $this->pending(1, 1, 500), 2 => $this->pending(2, 1, 400)]);
		$calls = 0;
		$this->notification->method('send')->willReturnCallback(function () use (&$calls): void {
			if (++$calls === 1) {
				throw new \LogicException('first one is broken');
			}
		});
		$mail = $this->createMock(MailChannel::class);
		$mail->method('send')->willThrowException(new \RuntimeException('smtp down'));

		$report = $this->withMail($mail)->process();
		$this->assertSame([1, 1], [$report->sent, $report->failed]);
	}

	public function testOldestBacklogIsServedFirst(): void {
		$this->watch(1);
		$this->watch(2);
		$this->queued([1 => $this->pending(1, 1, 300), 2 => $this->pending(2, 1, 900)]);
		$order = [];
		$this->notification->method('send')->willReturnCallback(function (Watch $watch) use (&$order): void {
			$order[] = $watch->getId();
		});
		$this->sender->process();
		$this->assertSame([2, 1], $order);
	}

	public function testMixedEntriesAreSummedUp(): void {
		$this->watch(1);
		$items = $this->pending(1, 2);
		$items[1]->setEvent(WatchOptions::EVENT_MODIFIED);
		$items[1]->setActorUid(null);
		$this->queued([1 => $items]);

		$this->notification->expects($this->once())->method('send')
			->with($this->anything(), $this->callback(function (AlertSummary $summary): bool {
				$this->assertSame(['created' => 1, 'modified' => 1, 'deleted' => 0, 'moved_in' => 0], $summary->counts);
				$this->assertTrue($summary->unknownActor);
				return true;
			}), self::NOW);
		$this->sender->process();
	}

	public function testTheMailGetsTheLinksStoredWithTheEventsAndNoneIsWorkedOutAgain(): void {
		$this->watch(1);
		$this->queued([1 => $this->pending(1, 2, 300, true)]);
		$links = $this->createMock(LinkResolver::class);
		$links->expects($this->never())->method('fileUrl');

		$this->notification->expects($this->once())->method('send');
		$this->mail->expects($this->once())->method('send')->with(
			$this->anything(),
			$this->callback(function (AlertSummary $summary): bool {
				$this->assertSame('https://stored.test/f/7', $summary->folder['url']);
				return true;
			}),
			$this->callback(function (array $entries): bool {
				$this->assertSame(['https://stored.test/f/1001', 'https://stored.test/f/1002'], array_map(static fn (AlertEntry $e): ?string => $e->url, $entries));
				return true;
			}),
			self::NOW,
		);
		$this->assertSame(1, $this->withLinks($links)->process()->sent);
	}

	public function testRowsWithoutStoredLinksFallBackOnWhatTheConfigurationAllows(): void {
		$this->watch(1);
		$this->queued([1 => $this->pending(1, 1)]);

		$this->mail->expects($this->once())->method('send')->with(
			$this->anything(),
			$this->callback(function (AlertSummary $summary): bool {
				$this->assertSame('https://nc.test/f/7', $summary->folder['url']);
				return true;
			}),
			$this->callback(function (array $entries): bool {
				$this->assertSame('https://nc.test/f/1001', $entries[0]->url);
				return true;
			}),
			self::NOW,
		);
		$this->assertSame(1, $this->sender->process()->sent);
	}

	public function testTheMailStillGoesOutWithoutLinksWhenNoAddressIsKnown(): void {
		$this->watch(1);
		$this->queued([1 => $this->pending(1, 2)]);
		$links = $this->createMock(LinkResolver::class);
		$links->method('fileUrl')->willReturn(null);

		$this->mail->expects($this->once())->method('send')->with(
			$this->anything(),
			$this->callback(function (AlertSummary $summary): bool {
				$this->assertNull($summary->folder['url']);
				return true;
			}),
			$this->callback(function (array $entries): bool {
				$this->assertSame([null, null], array_map(static fn (AlertEntry $e): ?string => $e->url, $entries));
				return true;
			}),
			self::NOW,
		);
		$this->assertSame(1, $this->withLinks($links)->process()->sent);
	}

	public function testNoLinkIsWorkedOutForANotificationOnlyWatch(): void {
		// the notification makes its own links when it is shown
		$this->watch(1, 'alice', WatchOptions::CHANNEL_NOTIFICATION);
		$this->queued([1 => $this->pending(1)]);
		$links = $this->createMock(LinkResolver::class);
		$links->expects($this->never())->method('fileUrl');

		$this->notification->expects($this->once())->method('send')->with(
			$this->anything(),
			$this->callback(function (AlertSummary $summary): bool {
				$this->assertNull($summary->folder['url']);
				return true;
			}),
			self::NOW,
		);
		$this->assertSame(1, $this->withLinks($links)->process()->sent);
	}

	public function testNoMailIsAttemptedWhenNextcloudCannotSendMail(): void {
		$this->watch(1);
		$this->queued([1 => $this->pending(1)]);
		$policy = $this->createMock(MailPolicy::class);
		$policy->method('isAllowed')->willReturn(false);

		$this->mail->expects($this->never())->method('send');
		$this->notification->expects($this->once())->method('send');
		$report = $this->withMailPolicy($policy)->process();
		$this->assertSame([1, 0, 0], [$report->sent, $report->failed, $report->mailSkipped]);
	}

	private function build(array $overrides): AlertSender {
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('UTC'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		return new AlertSender(
			$this->queue,
			$this->watches,
			$this->sendLog,
			new Aggregator(),
			$overrides['verifier'] ?? $this->verifier,
			$this->notification,
			$overrides['mail'] ?? $this->mail,
			$overrides['settings'] ?? $this->settings,
			$overrides['mailPolicy'] ?? $this->mailPolicy,
			$overrides['links'] ?? $this->links,
			$overrides['users'] ?? $this->users,
			$zone,
			$time,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function withSettings(AppSettings $settings): AlertSender {
		return $this->build(['settings' => $settings]);
	}

	private function withMailPolicy(MailPolicy $policy): AlertSender {
		return $this->build(['mailPolicy' => $policy]);
	}

	private function withLinks(LinkResolver $links): AlertSender {
		return $this->build(['links' => $links]);
	}

	private function withVerifier(BatchVerifier $verifier): AlertSender {
		return $this->build(['verifier' => $verifier]);
	}

	private function withMail(MailChannel $mail): AlertSender {
		return $this->build(['mail' => $mail]);
	}

	private function withUsers(IUserManager $users): AlertSender {
		return $this->build(['users' => $users]);
	}
}
