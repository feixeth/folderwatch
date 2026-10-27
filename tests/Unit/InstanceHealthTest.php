<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Service\AppSettings;
use OCA\FolderWatch\Service\InstanceHealth;
use OCA\FolderWatch\Service\MailPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstanceHealthTest extends TestCase {
	/**
	 * @param array<string, string> $system config.php values
	 */
	private function health(array $system = [], string $jobsMode = 'cron', bool $mailEnabled = true): InstanceHealth {
		$store = FakeAppConfig::create($this, ['core/backgroundjobs_mode' => $jobsMode, 'folderwatch/mail_enabled' => $mailEnabled]);
		return new InstanceHealth(new AppSettings($store->config), FakeSystemConfig::create($this, $system));
	}

	private function healthy(): array {
		return ['overwrite.cli.url' => 'https://cloud.example.org'] + FakeSystemConfig::withMail();
	}

	public function testAHealthyInstanceHasNoIssue(): void {
		$health = $this->health($this->healthy());
		$this->assertSame([], $health->getIssues(new FakeL10N()));
		$this->assertTrue($health->isCron());
		$this->assertTrue($health->isCliUrlUsable());
		$this->assertTrue($health->isMailConfigured());
	}

	#[DataProvider('modes')]
	public function testOnlyCronIsGoodEnough(string $mode, bool $ok): void {
		$this->assertSame($ok, $this->health($this->healthy(), $mode)->isCron());
	}

	public static function modes(): array {
		return ['cron' => ['cron', true], 'ajax' => ['ajax', false], 'webcron' => ['webcron', false]];
	}

	public function testTheJobsModeIsNamedInTheMessage(): void {
		$issues = $this->health($this->healthy(), 'webcron')->getIssues(new FakeL10N());
		$this->assertSame(['background_jobs'], array_column($issues, 'id'));
		$this->assertStringContainsString('(current mode: webcron)', $issues[0]['message']);
		$this->assertStringStartsWith('https://docs.nextcloud.com/', $issues[0]['docUrl']);
	}

	#[DataProvider('cliUrls')]
	public function testOverwriteCliUrl(string $url, bool $ok): void {
		$this->assertSame($ok, $this->health(['overwrite.cli.url' => $url] + FakeSystemConfig::withMail())->isCliUrlUsable());
	}

	public static function cliUrls(): array {
		return [
			'public address' => ['https://cloud.example.org', true],
			'missing' => ['', false],
			'localhost' => ['http://localhost', false],
			'localhost with port' => ['http://localhost:8080', false],
			'loopback' => ['http://127.0.0.1', false],
		];
	}

	#[DataProvider('mailSetups')]
	public function testMailIsConfiguredOnlyWithAWayToSendASenderAndADomain(array $config, bool $configured): void {
		$this->assertSame($configured, $this->health($config)->isMailConfigured());
	}

	public static function mailSetups(): array {
		$ok = FakeSystemConfig::withMail();
		return [
			'smtp with everything' => [$ok, true],
			'sendmail needs no host' => [['mail_smtpmode' => 'sendmail', 'mail_smtphost' => ''] + $ok, true],
			'qmail' => [['mail_smtpmode' => 'qmail'] + $ok, true],
			'mode none' => [['mail_smtpmode' => 'null'] + $ok, false],
			'smtp without a host' => [['mail_smtphost' => ''] + $ok, false],
			'no sender address' => [['mail_from_address' => ''] + $ok, false],
			'no domain' => [['mail_domain' => ''] + $ok, false],
			'a fresh Nextcloud: nothing set' => [[], false],
		];
	}

	public function testEveryIssueComesWithTheSameMessageEverywhere(): void {
		$health = $this->health([], 'ajax');
		$l = new FakeL10N();
		$byId = array_column($health->getIssues($l), 'message', 'id');
		$this->assertSame($health->describeBackgroundJobs($l), $byId['background_jobs']);
		$this->assertSame($health->describeCliUrl($l), $byId['cli_url']);
		$this->assertSame($health->describeMail($l), $byId['mail']);
	}

	public function testMessagesAreTranslated(): void {
		$byId = array_column($this->health([], 'ajax')->getIssues(new FakeL10N(true)), 'message', 'id');
		$this->assertStringContainsString('(mode actuel : ajax)', $byId['background_jobs']);
		$this->assertStringContainsString("n'est pas défini dans config.php", $byId['cli_url']);
		$this->assertStringContainsString("n'est pas configuré", $byId['mail']);
	}

	public function testMailPolicy(): void {
		$store = FakeAppConfig::create($this, ['folderwatch/mail_enabled' => true]);
		$settings = new AppSettings($store->config);
		$policy = new MailPolicy($settings, new InstanceHealth($settings, FakeSystemConfig::create($this, FakeSystemConfig::withMail())));
		$this->assertTrue($policy->isAllowed());
		$this->assertNull($policy->unavailableReason('a@example.org'));
		$this->assertSame('no_address', $policy->unavailableReason(''));

		$offStore = FakeAppConfig::create($this, ['folderwatch/mail_enabled' => false]);
		$off = new MailPolicy(new AppSettings($offStore->config), new InstanceHealth(new AppSettings($offStore->config), FakeSystemConfig::create($this, FakeSystemConfig::withMail())));
		$this->assertFalse($off->isAllowed());
		$this->assertSame('disabled', $off->unavailableReason('a@example.org'));

		$noSmtp = new MailPolicy($settings, new InstanceHealth($settings, FakeSystemConfig::create($this, [])));
		$this->assertFalse($noSmtp->isAllowed());
		$this->assertSame('not_configured', $noSmtp->unavailableReason('a@example.org'));
	}
}
