<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Alert\AlertEntry;
use OCA\FolderWatch\Alert\AlertSummary;
use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Notification\Notifier;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	private function notifier(): Notifier {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(static fn (string $app, ?string $lang = null): FakeL10N => new FakeL10N($lang === 'fr'));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $params): string => 'https://shown.example/index.php/f/' . $params['fileid']);
		$urls->method('imagePath')->willReturn('/custom_apps/folderwatch/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://shown.example' . $path);
		return new Notifier($factory, $urls);
	}

	/**
	 * @param list<AlertEntry> $entries
	 */
	private function notification(array $entries): INotification&\PHPUnit\Framework\MockObject\MockObject {
		$summary = AlertSummary::fromEntries($entries, ['id' => 7, 'name' => 'Docs', 'path' => '/Docs', 'url' => 'https://stale.example/f/7'], static fn (string $uid): string => ucfirst($uid));
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('folderwatch');
		$notification->method('getSubject')->willReturn('batch');
		// what the sender stores: no link at all
		$notification->method('getSubjectParameters')->willReturn($summary->withoutUrls()->toArray());
		$notification->method('setRichSubject')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setParsedMessage')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		$action = $this->createMock(IAction::class);
		$action->method('setParsedLabel')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$notification->method('createAction')->willReturn($action);
		return $notification;
	}

	public function testTheLinksAreMadeWhenTheNotificationIsShown(): void {
		$notification = $this->notification([new AlertEntry(100, WatchOptions::EVENT_CREATED, 'bob', 'a.pdf', 1)]);
		$notification->expects($this->once())->method('setRichSubject')->with(
			'{user} added {file} in {folder}',
			$this->callback(function (array $objects): bool {
				$this->assertSame('https://shown.example/index.php/f/7', $objects['folder']['link']);
				$this->assertSame('https://shown.example/index.php/f/100', $objects['file']['link']);
				return true;
			}),
		);
		$notification->expects($this->once())->method('setLink')->with('https://shown.example/index.php/f/100');
		$notification->expects($this->once())->method('addParsedAction');

		$this->notifier()->prepare($notification, 'en');
	}

	public function testSeveralItemsLinkToTheFolder(): void {
		$notification = $this->notification([
			new AlertEntry(100, WatchOptions::EVENT_CREATED, 'bob', 'a.pdf', 1),
			new AlertEntry(101, WatchOptions::EVENT_CREATED, 'bob', 'b.pdf', 2),
		]);
		$notification->expects($this->once())->method('setLink')->with('https://shown.example/index.php/f/7');
		$this->notifier()->prepare($notification, 'en');
	}

	public function testAStaleLinkInAnOldNotificationIsReplaced(): void {
		// notifications stored by an earlier version carry their own links: the shown ones win
		$entries = [new AlertEntry(100, WatchOptions::EVENT_CREATED, 'bob', 'a.pdf', 1, 'https://stale.example/f/100')];
		$summary = AlertSummary::fromEntries($entries, ['id' => 7, 'name' => 'Docs', 'path' => '/Docs', 'url' => 'https://stale.example/f/7'], static fn (string $uid): string => $uid);
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('folderwatch');
		$notification->method('getSubject')->willReturn('batch');
		$notification->method('getSubjectParameters')->willReturn($summary->toArray());
		$notification->method('setRichSubject')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('createAction')->willReturn($this->createMock(IAction::class));
		$notification->expects($this->once())->method('setLink')->with('https://shown.example/index.php/f/100');
		$this->notifier()->prepare($notification, 'en');
	}

	public function testTheSubjectIsInTheLanguageOfTheReader(): void {
		$notification = $this->notification([new AlertEntry(100, WatchOptions::EVENT_CREATED, 'bob', 'a.pdf', 1)]);
		$notification->expects($this->once())->method('setParsedSubject')->with('Bob a ajouté a.pdf dans Docs');
		$this->notifier()->prepare($notification, 'fr');
	}

	public function testOtherNotificationsAreNotOurs(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('talk');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare($notification, 'en');
	}
}
