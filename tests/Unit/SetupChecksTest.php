<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Service\AppSettings;
use OCA\FolderWatch\Service\InstanceHealth;
use OCA\FolderWatch\SetupCheck\BackgroundJobsCheck;
use OCA\FolderWatch\SetupCheck\CliUrlCheck;
use OCA\FolderWatch\SetupCheck\MailServerCheck;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

class SetupChecksTest extends TestCase {
	/**
	 * @param array<string, string> $system
	 * @return array<string, ISetupCheck>
	 */
	private function checks(array $system, string $jobsMode, bool $french = false): array {
		$store = FakeAppConfig::create($this, ['core/backgroundjobs_mode' => $jobsMode]);
		$health = new InstanceHealth(new AppSettings($store->config), FakeSystemConfig::create($this, $system));
		$l = new FakeL10N($french);
		return [
			'jobs' => new BackgroundJobsCheck($l, $health),
			'cli' => new CliUrlCheck($l, $health),
			'mail' => new MailServerCheck($l, $health),
		];
	}

	private function healthySystem(): array {
		return ['overwrite.cli.url' => 'https://cloud.example.org'] + FakeSystemConfig::withMail();
	}

	public function testAllThreeSucceedOnAHealthyInstance(): void {
		foreach ($this->checks($this->healthySystem(), 'cron') as $name => $check) {
			$result = $check->run();
			$this->assertSame(SetupResult::SUCCESS, $result->getSeverity(), $name);
			$this->assertNotEmpty($result->getDescription(), $name);
		}
	}

	public function testBackgroundJobsFailWithoutCron(): void {
		foreach (['ajax', 'webcron'] as $mode) {
			$result = $this->checks($this->healthySystem(), $mode)['jobs']->run();
			$this->assertSame(SetupResult::WARNING, $result->getSeverity(), $mode);
			$this->assertStringContainsString("(current mode: $mode)", (string)$result->getDescription());
			$this->assertSame(InstanceHealth::DOC_BACKGROUND_JOBS, $result->getLinkToDoc());
		}
		$this->assertSame(SetupResult::SUCCESS, $this->checks($this->healthySystem(), 'ajax')['cli']->run()->getSeverity(), 'the other checks are not affected');
	}

	public function testCliUrlFailsWhenMissingOrLocal(): void {
		$withoutUrl = FakeSystemConfig::withMail();
		foreach ([$withoutUrl, ['overwrite.cli.url' => ''] + $withoutUrl, ['overwrite.cli.url' => 'http://localhost'] + $withoutUrl, ['overwrite.cli.url' => 'http://127.0.0.1:8080'] + $withoutUrl] as $system) {
			$result = $this->checks($system, 'cron')['cli']->run();
			$this->assertSame(SetupResult::WARNING, $result->getSeverity(), json_encode($system));
			$this->assertSame(InstanceHealth::DOC_CLI_URL, $result->getLinkToDoc());
		}
	}

	public function testMailFailsWithoutAMailServer(): void {
		$result = $this->checks(['overwrite.cli.url' => 'https://cloud.example.org'], 'cron')['mail']->run();
		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('not configured', (string)$result->getDescription());
		$this->assertSame(InstanceHealth::DOC_MAIL, $result->getLinkToDoc());
	}

	public function testTheAdministrationPageShowsTheSameMessagesAsTheChecks(): void {
		$system = [];
		$checks = $this->checks($system, 'ajax');
		$store = FakeAppConfig::create($this, ['core/backgroundjobs_mode' => 'ajax']);
		$issues = array_column((new InstanceHealth(new AppSettings($store->config), FakeSystemConfig::create($this, $system)))->getIssues(new FakeL10N()), 'message', 'id');
		$this->assertSame($issues['background_jobs'], $checks['jobs']->run()->getDescription());
		$this->assertSame($issues['cli_url'], $checks['cli']->run()->getDescription());
		$this->assertSame($issues['mail'], $checks['mail']->run()->getDescription());
	}

	public function testNamesAndCategories(): void {
		$checks = $this->checks($this->healthySystem(), 'cron');
		$this->assertSame(['Folder Watch: background jobs', 'Folder Watch: overwrite.cli.url', 'Folder Watch: e-mail server'], array_map(static fn (ISetupCheck $c): string => $c->getName(), array_values($checks)));
		foreach ($checks as $check) {
			$this->assertContains($check->getCategory(), ['system', 'config']);
		}
	}

	public function testChecksAreTranslated(): void {
		$checks = $this->checks([], 'ajax', true);
		$this->assertSame('Folder Watch : tâches de fond', $checks['jobs']->getName());
		$this->assertStringContainsString('tâches de fond ne sont pas exécutées', (string)$checks['jobs']->run()->getDescription());
	}
}
