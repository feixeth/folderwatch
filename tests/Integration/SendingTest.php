<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Integration;

use OCA\FolderWatch\Db\WatchMapper;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * From a file operation to the notification and the mail, with the real Notifications app
 * and the dev Mailpit.
 */
class SendingTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		// the tests run in the CLI, where links are built from overwrite.cli.url
		$this->setSystemValue('overwrite.cli.url', 'https://cloud.test');
	}

	protected function tearDown(): void {
		$config = $this->get(IAppConfig::class);
		$config->deleteKey('folderwatch', 'mail_enabled');
		$config->deleteKey('folderwatch', 'max_sends_per_hour');
		parent::tearDown();
	}

	/** @return array{string, string} alice (the watcher) and bob (the actor), Docs shared and watched */
	private function aliceWatchingBobsWork(array $watchOptions = []): array {
		$alice = $this->createUser('alice', 'Alice Martin');
		$bob = $this->createUser('bob', 'Bob Durand');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs', $watchOptions);
		return [$alice, $bob];
	}

	public function testABatchIsOneNotificationAndOneMail(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$docs = $this->actAs($bob)->get('Docs');
		foreach (['rapport.pdf', 'budget.xlsx', 'notes.txt'] as $name) {
			$docs->newFile($name, 'x');
		}
		$this->flush();

		$report = $this->sender()->process(true);

		$this->assertSame(1, $report->sent);
		$notifications = $this->notificationsOf($alice);
		$this->assertCount(1, $notifications);
		$this->assertSame(3, $notifications[0]['total']);
		$this->assertSame('Bob Durand', $notifications[0]['actors'][0]['name']);
		$this->assertSame('/Docs', $notifications[0]['folder']['path']);
		$this->assertNull($notifications[0]['folder']['url'], 'a notification keeps no link: it makes them when it is shown');
		$this->assertSame('', $notifications[0]['link']);
		$this->assertSame([], $this->queue(), 'the queue is purged after sending');

		$mails = $this->mailsTo($this->emailOf($alice));
		if ($mails === null) {
			$this->markTestIncomplete('Mailpit is not reachable: the notification was checked, the mail was not.');
		}
		$this->assertCount(1, $mails);
		$this->assertSame('3 new items in Docs by Bob Durand', $mails[0]['Subject']);
		foreach (['rapport.pdf', 'budget.xlsx', 'notes.txt'] as $name) {
			$this->assertStringContainsString($name, $mails[0]['Text']);
			$this->assertStringContainsString($name, $mails[0]['Html']);
		}
		$this->assertStringContainsString('Bob Durand', $mails[0]['Text']);
	}

	public function testTheMailNeverCarriesFileContents(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$this->actAs($bob)->get('Docs')->newFile('secret.txt', 'TOP-SECRET-CONTENT-4711');
		$this->flush();
		$this->sender()->process(true);

		$mails = $this->mailsTo($this->emailOf($alice));
		if ($mails === null) {
			$this->markTestSkipped('Mailpit is not reachable.');
		}
		$this->assertCount(1, $mails);
		$this->assertStringNotContainsString('TOP-SECRET-CONTENT-4711', $mails[0]['Text'] . $mails[0]['Html']);
		$this->assertStringContainsString('secret.txt', $mails[0]['Text']);
	}

	public function testWithoutAnAddressTheNotificationStillComes(): void {
		$alice = $this->createUser('alice', 'Alice Martin', false);
		$bob = $this->createUser('bob', 'Bob Durand');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs');
		$this->actAs($bob)->get('Docs')->newFile('a.txt', 'x');
		$this->flush();

		$report = $this->sender()->process(true);

		$this->assertSame([1, 1], [$report->sent, $report->mailSkipped]);
		$this->assertCount(1, $this->notificationsOf($alice));
	}

	public function testTheChannelsOfTheWatchAreRespected(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork(['channels' => ['notification']]);
		$this->actAs($bob)->get('Docs')->newFile('a.txt', 'x');
		$this->flush();
		$this->sender()->process(true);

		$this->assertCount(1, $this->notificationsOf($alice));
		$mails = $this->mailsTo($this->emailOf($alice));
		$this->assertTrue($mails === null || $mails === []);
	}

	public function testTheAdminCanSwitchMailOff(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$this->get(IAppConfig::class)->setValueBool('folderwatch', 'mail_enabled', false);
		$this->actAs($bob)->get('Docs')->newFile('a.txt', 'x');
		$this->flush();
		$this->sender()->process(true);

		$this->assertCount(1, $this->notificationsOf($alice));
		$mails = $this->mailsTo($this->emailOf($alice));
		$this->assertTrue($mails === null || $mails === []);
	}

	public function testALongListIsCutAndSummedUp(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$docs = $this->actAs($bob)->get('Docs');
		for ($i = 1; $i <= 60; $i++) {
			$docs->newFile(sprintf('file-%02d.txt', $i), 'x');
		}
		$this->flush();
		$this->sender()->process(true);

		$this->assertSame(60, $this->notificationsOf($alice)[0]['total']);
		$mails = $this->mailsTo($this->emailOf($alice));
		if ($mails === null) {
			$this->markTestSkipped('Mailpit is not reachable.');
		}
		$this->assertSame('60 new items in Docs by Bob Durand', $mails[0]['Subject']);
		$this->assertSame(50, preg_match_all('/file-\d\d\.txt/', $mails[0]['Text']));
		$this->assertStringContainsString('and 10 other items', $mails[0]['Text']);
	}

	public function testAnImmediateBurstWaitsUntilItIsQuiet(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$docs = $this->actAs($bob)->get('Docs');
		$docs->newFile('a.txt', 'x');
		$this->flush();

		$report = $this->sender()->process();
		$this->assertSame([0, 1], [$report->sent, $report->waiting]);
		$this->assertSame([], $this->notificationsOf($alice));

		$this->ageQueue(150); // quiet for 2.5 minutes
		$report = $this->sender()->process();
		$this->assertSame(1, $report->sent);
		$this->assertCount(1, $this->notificationsOf($alice));
	}

	public function testTheHourlyCapPostponesAlertsWithoutLosingThem(): void {
		$alice = $this->createUser('alice', 'Alice Martin');
		$bob = $this->createUser('bob', 'Bob Durand');
		$home = $this->actAs($alice);
		$home->newFolder('One');
		$home->newFolder('Two');
		$this->share($alice, 'One', $bob);
		$this->share($alice, 'Two', $bob);
		$this->watch($alice, '/One');
		$this->watch($alice, '/Two');
		$this->get(IAppConfig::class)->setValueInt('folderwatch', 'max_sends_per_hour', 1);

		$bobHome = $this->actAs($bob);
		$bobHome->get('One')->newFile('a.txt', 'x');
		$bobHome->get('Two')->newFile('b.txt', 'x');
		$this->flush();

		$report = $this->sender()->process(true);
		$this->assertSame([1, 1], [$report->sent, $report->capped]);
		$this->assertCount(1, $this->notificationsOf($alice));
		$this->assertCount(1, $this->queue(), 'the postponed alert stays queued');

		// more activity in the folder that was postponed: it all leaves in one alert once the cap allows
		$this->actAs($bob)->get('Two')->newFile('c.txt', 'x');
		$this->flush();
		$this->get(IAppConfig::class)->setValueInt('folderwatch', 'max_sends_per_hour', 5);
		$report = $this->sender()->process(true);

		$this->assertSame(1, $report->sent);
		$notifications = $this->notificationsOf($alice);
		$this->assertCount(2, $notifications);
		$this->assertSame(2, $notifications[1]['total']);
		$this->assertSame([], $this->queue());
	}

	public function testALostShareSilencesTheAlertAtSendingTime(): void {
		$alice = $this->createUser('alice', 'Alice Martin');
		$bob = $this->createUser('bob', 'Bob Durand');
		$carol = $this->createUser('carol', 'Carol Petit');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->share($alice, 'Docs', $carol);
		$carolWatch = $this->watch($carol, '/Docs');
		$this->actAs($bob)->get('Docs')->newFile('a.txt', 'x');
		$this->flush();
		$this->assertSame(['carol:created:a.txt'], $this->queue());

		// the share is removed after the event was queued, before the alert leaves
		$manager = $this->get(\OCP\Share\IManager::class);
		foreach ($manager->getSharesBy($alice, \OCP\Share\IShare::TYPE_USER) as $share) {
			if ($share->getSharedWith() === $carol) {
				$manager->deleteShare($share);
			}
		}
		$this->newProcess();

		$report = $this->sender()->process(true);

		$this->assertSame([0, 1], [$report->sent, $report->dropped]);
		$this->assertSame([], $this->notificationsOf($carol));
		$this->assertSame([], $this->queue());
		$this->assertNotNull($this->get(WatchMapper::class)->find($carolWatch)?->getInaccessibleSince(), 'the watch is suspended, not deleted');
	}

	public function testAFileDeletedAgainBeforeSendingIsNotAnnounced(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$docs = $this->actAs($bob)->get('Docs');
		$docs->newFile('keep.txt', 'x');
		$docs->newFile('gone.txt', 'x');
		$docs->get('gone.txt')->delete();
		$this->flush();

		$this->sender()->process(true);

		$notifications = $this->notificationsOf($alice);
		$this->assertCount(1, $notifications);
		$this->assertSame(1, $notifications[0]['total']);
		$this->assertSame('keep.txt', $notifications[0]['file']['name']);
	}

	public function testRemovalsAreAnnouncedWithoutALink(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork(['events' => ['deleted']]);
		$this->actAs($alice)->get('Docs')->newFile('old.txt', 'x');
		$this->actAs($bob)->get('Docs/old.txt')->delete();
		$this->flush();
		$this->sender()->process(true);

		$notification = $this->notificationsOf($alice)[0];
		$this->assertSame(1, $notification['total']);
		$this->assertSame('old.txt', $notification['file']['name']);
		$this->assertNull($notification['file']['url']);
		$shown = $this->renderedNotificationsOf($alice)[0];
		$this->assertStringEndsWith('/f/' . $notification['folder']['id'], $shown['link'], 'a removal opens the folder');

		$mails = $this->mailsTo($this->emailOf($alice));
		if ($mails !== null) {
			$this->assertSame('Bob Durand removed old.txt from Docs', $mails[0]['Subject']);
			$this->assertStringNotContainsString('/f/' . $notification['file']['id'] . '"', $mails[0]['Html']);
		}
	}

	public function testDigestsAreHeldBackUntilTheirHour(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork(['frequency' => 'hourly']);
		$this->actAs($bob)->get('Docs')->newFile('a.txt', 'x');
		$this->flush();
		$this->ageQueue(400); // old, but queued in the current hour or the previous one

		$report = $this->sender()->process();
		$sent = $report->sent;
		// 400 s old: due only if an hour boundary was crossed in the meantime
		$crossed = (int)floor(time() / 3600) !== (int)floor((time() - 400) / 3600);
		$this->assertSame($crossed ? 1 : 0, $sent);

		$this->ageQueue(3700);
		$this->assertSame(1, $this->sender()->process()->sent + $sent);
	}

	public function testThePersonalLanguageDecidesTheLanguageOfTheMail(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$this->get(IConfig::class)->setUserValue($alice, 'core', 'lang', 'fr');
		$this->actAs($bob)->get('Docs')->newFile('a.txt', 'x');
		$this->flush();
		$this->sender()->process(true);

		$mails = $this->mailsTo($this->emailOf($alice));
		if ($mails === null) {
			$this->markTestSkipped('Mailpit is not reachable.');
		}
		$this->assertSame('Bob Durand a ajouté a.txt dans Docs', $mails[0]['Subject']);
		$this->assertStringContainsString('Ouvrir le dossier', $mails[0]['Text']);
	}

	public function testEveryKindOfAlertIsShownByTheNotificationsApp(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork(['events' => ['created', 'modified', 'deleted', 'moved_in']]);
		$bobDocs = $this->actAs($bob)->get('Docs');

		$bobDocs->newFile('one.txt', 'x');
		$this->flush();
		$this->sender()->process(true);
		$this->actAs($bob)->get('Docs/one.txt')->putContent('y');
		$this->flush();
		$this->sender()->process(true);
		$this->actAs($bob)->get('Docs/one.txt')->delete();
		$this->flush();
		$this->sender()->process(true);

		$docs = $this->actAs($bob)->get('Docs');
		foreach (['a.txt', 'b.txt', 'c.txt'] as $name) {
			$docs->newFile($name, 'x');
		}
		$this->flush();
		$this->sender()->process(true);

		$docs = $this->actAs($bob)->get('Docs');
		$docs->newFile('d.txt', 'x');
		$docs->get('a.txt')->putContent('changed');
		$docs->get('b.txt')->delete();
		$this->flush();
		$this->sender()->process(true);

		$english = $this->renderedNotificationsOf($alice, 'en');
		$this->assertSame([
			'Bob Durand added one.txt in Docs',
			'Bob Durand modified one.txt in Docs',
			'Bob Durand removed one.txt from Docs',
			'3 new items in Docs by Bob Durand',
			'3 changes in Docs by Bob Durand',
		], array_column($english, 'subject'));
		$this->assertSame(['', '', '', '', '1 added, 1 modified, 1 removed'], array_column($english, 'message'));
		foreach ($english as $notification) {
			$this->assertSame(['Open'], $notification['actions']);
		}

		$this->assertSame([
			'Bob Durand a ajouté one.txt dans Docs',
			'Bob Durand a modifié one.txt dans Docs',
			'Bob Durand a supprimé one.txt de Docs',
			'3 nouveaux éléments dans Docs par Bob Durand',
			'3 modifications dans Docs par Bob Durand',
		], array_column($this->renderedNotificationsOf($alice, 'fr'), 'subject'));
	}

	public function testTheMailCarriesLinksBuiltFromOverwriteCliUrlWhenThereIsNoWebRequest(): void {
		$this->setSystemValue('overwrite.cli.url', 'https://cloud.example.org/nextcloud/');
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$file = $this->actAs($bob)->get('Docs')->newFile('rapport.pdf', 'x');
		$folderId = $this->actAs($alice)->get('Docs')->getId();
		$this->flush();
		$this->sender()->process(true);

		$mails = $this->mailsTo($this->emailOf($alice));
		if ($mails === null) {
			$this->markTestSkipped('Mailpit is not reachable.');
		}
		$this->assertStringContainsString('href="https://cloud.example.org/nextcloud/index.php/f/' . $file->getId() . '"', $mails[0]['Html']);
		$this->assertStringContainsString('https://cloud.example.org/nextcloud/index.php/f/' . $file->getId(), $mails[0]['Text']);
		$this->assertStringContainsString('href="https://cloud.example.org/nextcloud/index.php/f/' . $folderId . '"', $mails[0]['Html'], 'the button opens the folder');
	}

	public function testTheLinksAreStoredWhenTheEventHappensAndUsedAsTheyAre(): void {
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$file = $this->actAs($bob)->get('Docs')->newFile('rapport.pdf', 'x');
		$folderId = $this->actAs($alice)->get('Docs')->getId();
		$this->assertSame(['rapport.pdf' => ['https://cloud.test/index.php/f/' . $file->getId(), 'https://cloud.test/index.php/f/' . $folderId]], $this->queueLinks());

		// the address changes before the alert leaves: the stored links are the ones used
		$this->setSystemValue('overwrite.cli.url', 'https://other.example.org');
		$this->sender()->process(true);

		$mails = $this->mailsTo($this->emailOf($alice));
		if ($mails === null) {
			$this->markTestSkipped('Mailpit is not reachable.');
		}
		$this->assertStringContainsString('https://cloud.test/index.php/f/' . $file->getId(), $mails[0]['Html']);
		$this->assertStringNotContainsString('other.example.org', $mails[0]['Html']);
	}

	/**
	 * (An empty or absent overwrite.cli.url is covered by the unit tests and by tests/integration.sh: under
	 * PHPUnit Nextcloud has no address at all to fall back on, and the Teams app then refuses to mount
	 * group folders, which would stop the scenario before it starts.)
	 *
	 * @return array<string, array{?string}>
	 */
	public static function unusableCliUrls(): array {
		return ['localhost' => ['http://localhost:8080'], 'localhost in capitals' => ['HTTP://LOCALHOST'], 'loopback' => ['http://127.0.0.1'], 'IPv6 loopback' => ['http://[::1]:8080']];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unusableCliUrls')]
	public function testWithoutAnUsableAddressTheMailGoesOutWithoutLinks(?string $cliUrl): void {
		$this->setSystemValue('overwrite.cli.url', $cliUrl);
		$this->get(IAppConfig::class)->deleteKey('folderwatch', 'link_warning_at');
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$docs = $this->actAs($bob)->get('Docs');
		$docs->newFile('a.txt', 'x');
		$docs->newFile('b.txt', 'x');
		$this->assertSame(['a.txt' => [null, null], 'b.txt' => [null, null]], $this->queueLinks());

		$report = $this->sender()->process(true);

		$this->assertSame(1, $report->sent);
		$this->assertCount(1, $this->notificationsOf($alice), 'the notification does not depend on the configuration');
		$shown = $this->renderedNotificationsOf($alice)[0];
		$this->assertStringContainsString('/f/', $shown['link'], 'it makes its own links');
		$mails = $this->mailsTo($this->emailOf($alice));
		if ($mails === null) {
			$this->markTestSkipped('Mailpit is not reachable.');
		}
		$this->assertCount(1, $mails);
		$this->assertSame('2 new items in Docs by Bob Durand', $mails[0]['Subject']);
		$this->assertStringContainsString('a.txt', $mails[0]['Text']);
		$this->assertStringNotContainsString('href=', $mails[0]['Html'], 'no link at all, not even to the folder');
		$this->assertStringNotContainsString('http://', $mails[0]['Text']);
		$this->assertStringNotContainsString('https://', $mails[0]['Text']);
	}

	public function testTheMissingAddressIsReportedInTheLogOnlyOncePerDay(): void {
		$this->setSystemValue('overwrite.cli.url', 'http://localhost');
		$config = $this->get(IAppConfig::class);
		$config->deleteKey('folderwatch', 'link_warning_at');
		[, $bob] = $this->aliceWatchingBobsWork();
		$docs = $this->actAs($bob)->get('Docs');

		$docs->newFile('a.txt', 'x');
		$first = $config->getValueInt('folderwatch', 'link_warning_at', 0, true);
		$this->assertGreaterThan(0, $first, 'the first event warns');
		$this->resetCaches();
		$docs->newFile('b.txt', 'x');
		$this->assertSame($first, $config->getValueInt('folderwatch', 'link_warning_at', 0, true), 'the second one does not');
	}

	public function testNoMailIsSentWhenNextcloudHasNoMailServer(): void {
		$this->setSystemValue('mail_smtpmode', 'null');
		[$alice, $bob] = $this->aliceWatchingBobsWork();
		$this->actAs($bob)->get('Docs')->newFile('a.txt', 'x');
		$this->flush();

		$report = $this->sender()->process(true);

		$this->assertSame([1, 0, 0], [$report->sent, $report->failed, $report->mailSkipped], 'sent by notification, nothing failed, no mail even tried');
		$this->assertCount(1, $this->notificationsOf($alice));
		$mails = $this->mailsTo($this->emailOf($alice));
		$this->assertTrue($mails === null || $mails === []);
		$this->assertSame([], $this->queue(), 'the alert is consumed, not retried forever');
	}

	public function testAMailOnlyWatchOnAnInstanceWithoutMailIsDroppedNotRetried(): void {
		$this->setSystemValue('mail_smtpmode', 'null');
		[, $bob] = $this->aliceWatchingBobsWork(['channels' => ['mail']]);
		$this->actAs($bob)->get('Docs')->newFile('a.txt', 'x');
		$this->flush();

		$report = $this->sender()->process(true);

		$this->assertSame([0, 0], [$report->sent, $report->failed]);
		$this->assertSame([], $this->queue());
	}
}
