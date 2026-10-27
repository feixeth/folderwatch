<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Listener\LoadFilesScriptsListener;
use OCA\FolderWatch\Service\AppSettings;
use OCA\FolderWatch\Service\InstanceHealth;
use OCA\FolderWatch\Service\MailPolicy;
use OCA\FolderWatch\Service\ScriptLoader;
use OCA\FolderWatch\Service\UserSettings;
use OCA\FolderWatch\Service\WatchService;
use OCA\FolderWatch\Settings\Admin;
use OCA\FolderWatch\Settings\AdminSection;
use OCA\FolderWatch\Settings\Personal;
use OCA\FolderWatch\Settings\PersonalSection;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettingsPagesTest extends TestCase {
	public function testSectionsAreIdentifiedByTheAppId(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => "/apps/$app/img/$file");
		foreach ([new PersonalSection(new FakeL10N(), $urls), new AdminSection(new FakeL10N(), $urls)] as $section) {
			$this->assertSame('folderwatch', $section->getID());
			$this->assertSame('Folder Watch', $section->getName());
			$this->assertSame('/apps/folderwatch/img/app-dark.svg', $section->getIcon());
		}
		$this->assertSame('Folder Watch', (new PersonalSection(new FakeL10N(), $urls))->getName());
	}

	/**
	 * @param array<string, string|bool> $instance config.php values (mail_*, overwrite.cli.url) and app settings
	 * @return array{Personal, array<string, mixed>}
	 */
	private function personalPage(?string $email, array $instance = [], string $jobsMode = 'cron', bool $mailEnabled = true): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getEMailAddress')->willReturn($email);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$watches = $this->createMock(WatchService::class);
		$watches->method('list')->with('alice')->willReturn([['id' => 1]]);
		$userSettings = $this->createMock(UserSettings::class);
		$userSettings->method('ignoreOwnActions')->willReturn(false);
		$store = FakeAppConfig::create($this, ['folderwatch/mail_enabled' => $mailEnabled, 'folderwatch/max_watches_per_user' => 12, 'core/backgroundjobs_mode' => $jobsMode]);
		$settings = new AppSettings($store->config);
		$health = new InstanceHealth($settings, FakeSystemConfig::create($this, $instance + FakeSystemConfig::withMail()));

		$provided = [];
		$state = $this->createMock(IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(function (string $key, $data) use (&$provided): void {
			$provided[$key] = $data;
		});

		$page = new Personal($state, $this->scripts('personal'), $watches, $userSettings, $settings, new MailPolicy($settings, $health), $health, $session);
		$page->getForm();
		return [$page, $provided['personal']];
	}

	public function testPersonalPageHandsOverTheStateTheFrontendReads(): void {
		[$page, $state] = $this->personalPage('alice@example.org');

		$this->assertSame([
			'watches' => [['id' => 1]],
			'ignoreOwnActions' => false,
			'maxWatches' => 12,
			'mailUnavailable' => null,
			'delayedAlerts' => false,
		], $state);
		$this->assertSame('folderwatch', $page->getSection());
	}

	public static function mailReasons(): array {
		return [
			'the administrator switched it off' => [['mail_enabled' => false], 'alice@example.org', 'disabled'],
			'no mail server on the instance' => [['mail_smtpmode' => 'null'], 'alice@example.org', 'not_configured'],
			'no smtp host' => [['mail_smtphost' => ''], 'alice@example.org', 'not_configured'],
			'no sender address' => [['mail_from_address' => ''], 'alice@example.org', 'not_configured'],
			'no address on the account' => [[], '', 'no_address'],
			'no address (null)' => [[], null, 'no_address'],
			'everything is there' => [[], 'alice@example.org', null],
			'sendmail needs no host' => [['mail_smtpmode' => 'sendmail', 'mail_smtphost' => ''], 'alice@example.org', null],
		];
	}

	#[DataProvider('mailReasons')]
	public function testPersonalPageSaysWhyTheMailChannelCannotBeUsed(array $instance, ?string $email, ?string $reason): void {
		$mailEnabled = $instance['mail_enabled'] ?? true;
		unset($instance['mail_enabled']);
		[, $state] = $this->personalPage($email, $instance, 'cron', $mailEnabled);
		$this->assertSame($reason, $state['mailUnavailable']);
	}

	public function testTheAdministratorSwitchComesBeforeTheOtherReasons(): void {
		[, $state] = $this->personalPage('', ['mail_smtpmode' => 'null'], 'cron', false);
		$this->assertSame('disabled', $state['mailUnavailable']);
	}

	#[DataProvider('jobModes')]
	public function testPersonalPageFlagsLateAlertsUnlessCronRuns(string $mode, bool $late): void {
		[, $state] = $this->personalPage('alice@example.org', [], $mode);
		$this->assertSame($late, $state['delayedAlerts']);
	}

	public static function jobModes(): array {
		return ['cron' => ['cron', false], 'ajax' => ['ajax', true], 'webcron' => ['webcron', true]];
	}

	public function testAdminPageHandsOverSettingsDefaultsAndTheIssuesOfTheInstance(): void {
		$store = FakeAppConfig::create($this, ['core/backgroundjobs_mode' => 'webcron']);
		$settings = new AppSettings($store->config);
		$health = new InstanceHealth($settings, FakeSystemConfig::create($this, ['overwrite.cli.url' => 'http://localhost', 'mail_smtpmode' => 'null']));
		$provided = [];
		$state = $this->createMock(IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(function (string $key, $data) use (&$provided): void {
			$provided[$key] = $data;
		});

		(new Admin($state, $this->scripts('admin'), $settings, $health, new FakeL10N()))->getForm();

		$this->assertSame(AppSettings::DEFAULT_PATTERNS, $provided['admin']['defaultPatterns']);
		$this->assertSame(50, $provided['admin']['settings']['maxWatchesPerUser']);
		$this->assertSame(['background_jobs', 'cli_url', 'mail'], array_column($provided['admin']['issues'], 'id'));
	}

	public function testAdminPageHasNoIssuesOnAHealthyInstance(): void {
		$store = FakeAppConfig::create($this, ['core/backgroundjobs_mode' => 'cron']);
		$settings = new AppSettings($store->config);
		$health = new InstanceHealth($settings, FakeSystemConfig::create($this, ['overwrite.cli.url' => 'https://cloud.example.org'] + FakeSystemConfig::withMail()));
		$provided = [];
		$state = $this->createMock(IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(function (string $key, $data) use (&$provided): void {
			$provided[$key] = $data;
		});

		(new Admin($state, $this->scripts('admin'), $settings, $health, new FakeL10N()))->getForm();
		$this->assertSame([], $provided['admin']['issues']);
	}

	/** A script loader that expects to be asked for exactly this script. */
	private function scripts(string $name, bool $init = false): ScriptLoader {
		$loader = $this->createMock(ScriptLoader::class);
		$loader->expects($this->once())->method($init ? 'addInitScript' : 'addScript')->with($name);
		return $loader;
	}

	private function filesListener(?string $uid, array $fileIds, array &$provided, ?ScriptLoader $loader = null): LoadFilesScriptsListener {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$mapper = $this->createMock(WatchMapper::class);
		$mapper->method('findByUser')->with($uid)->willReturn(array_map(static fn (int $id) => WatchFactory::make($id, (string)$uid, $id * 10), $fileIds));
		$state = $this->createMock(IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(function (string $key, $data) use (&$provided): void {
			$provided[$key] = $data;
		});
		return new LoadFilesScriptsListener($session, $mapper, $state, $loader ?? $this->scripts('files-action', true));
	}

	public function testFilesPageGetsTheIdsOfTheWatchedFolders(): void {
		$provided = [];
		$this->filesListener('alice', [1, 2], $provided)->handle(new LoadAdditionalScriptsEvent());
		$this->assertSame(['watchedFileIds' => [10, 20]], $provided);
	}

	public function testPublicPagesGetNothing(): void {
		$provided = [];
		$mapper = $this->createMock(WatchMapper::class);
		$mapper->expects($this->never())->method('findByUser');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$state = $this->createMock(IInitialState::class);
		$state->expects($this->never())->method('provideInitialState');
		(new LoadFilesScriptsListener($session, $mapper, $state, $this->createMock(ScriptLoader::class)))->handle(new LoadAdditionalScriptsEvent());
		$this->assertSame([], $provided);
	}

	public function testOtherEventsAreIgnored(): void {
		$provided = [];
		$loader = $this->createMock(ScriptLoader::class);
		$loader->expects($this->never())->method('addInitScript');
		$this->filesListener('alice', [1], $provided, $loader)->handle(new class() extends Event {
		});
		$this->assertSame([], $provided);
	}
}
