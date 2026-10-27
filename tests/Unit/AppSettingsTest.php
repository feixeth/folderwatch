<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use InvalidArgumentException;
use OCA\FolderWatch\Service\AppSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AppSettingsTest extends TestCase {
	private FakeAppConfig $store;
	private AppSettings $settings;

	protected function setUp(): void {
		$this->store = FakeAppConfig::create($this);
		$this->settings = new AppSettings($this->store->config);
	}

	public function testDefaults(): void {
		$this->assertSame([
			'mailEnabled' => true,
			'maxWatchesPerUser' => 50,
			'maxSendsPerHour' => 20,
			'ignoredPatterns' => ['*.part', '.~lock.*', '~$*', '.DS_Store', 'Thumbs.db'],
		], $this->settings->toArray());
	}

	public function testUpdateStoresWhatIsGivenAndOnlyThat(): void {
		$this->settings->update(['maxSendsPerHour' => 5, 'ignoredPatterns' => ['*.tmp', ' draft-* ', '', '*.tmp']]);

		$this->assertSame(5, $this->settings->getMaxSendsPerHour());
		$this->assertSame(['*.tmp', 'draft-*'], $this->settings->getIgnoredPatterns());
		$this->assertTrue($this->settings->isMailEnabled());
		$this->assertSame(50, $this->settings->getMaxWatchesPerUser());
		$this->assertCount(2, $this->store->values);
	}

	public function testZeroMeansNoLimitForSendsButNotForWatches(): void {
		$this->settings->update(['maxSendsPerHour' => 0]);
		$this->assertSame(0, $this->settings->getMaxSendsPerHour());
		$this->expectException(InvalidArgumentException::class);
		$this->settings->update(['maxWatchesPerUser' => 0]);
	}

	public function testAnEmptyListOfPatternsIsAllowed(): void {
		$this->settings->update(['ignoredPatterns' => []]);
		$this->assertSame([], $this->settings->getIgnoredPatterns());
	}

	public static function invalidValues(): array {
		return [
			'mail as a string' => [['mailEnabled' => 'yes'], 'mailEnabled'],
			'watches as a string' => [['maxWatchesPerUser' => '10'], 'maxWatchesPerUser'],
			'watches too many' => [['maxWatchesPerUser' => 1001], 'maxWatchesPerUser'],
			'sends negative' => [['maxSendsPerHour' => -1], 'maxSendsPerHour'],
			'sends too many' => [['maxSendsPerHour' => 1001], 'maxSendsPerHour'],
			'sends as a float' => [['maxSendsPerHour' => 2.5], 'maxSendsPerHour'],
			'patterns not a list' => [['ignoredPatterns' => ['a' => '*.tmp']], 'ignoredPatterns'],
			'patterns a string' => [['ignoredPatterns' => '*.tmp'], 'ignoredPatterns'],
			'a pattern that is not a string' => [['ignoredPatterns' => [12]], 'ignoredPatterns'],
			'a pattern with a line break' => [['ignoredPatterns' => ["a\nb"]], 'ignoredPatterns'],
			'a pattern too long' => [['ignoredPatterns' => [str_repeat('a', 256)]], 'ignoredPatterns'],
			'too many patterns' => [['ignoredPatterns' => array_map(static fn (int $i): string => "f$i", range(1, 101))], 'ignoredPatterns'],
		];
	}

	#[DataProvider('invalidValues')]
	public function testInvalidValuesAreRefusedAndNothingIsSaved(array $values, string $field): void {
		try {
			// a valid change first: it must not be applied either
			$this->settings->update($values + ['maxSendsPerHour' => 7]);
			$this->fail('Expected an exception');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($field, $e->getMessage());
		}
		$this->assertSame(20, $this->settings->getMaxSendsPerHour());
		$this->assertSame([], $this->store->values);
	}

	public function testPatternsCanBeParsedFromText(): void {
		$this->assertSame(['a', 'b c'], AppSettings::parsePatterns("a\r\n\n  b c \n"));
		$this->assertSame([], AppSettings::parsePatterns(''));
	}

	public function testBackgroundJobsModeComesFromTheCore(): void {
		$this->assertSame('ajax', $this->settings->getBackgroundJobsMode());
		$store = FakeAppConfig::create($this, ['core/backgroundjobs_mode' => 'cron']);
		$this->assertSame('cron', (new AppSettings($store->config))->getBackgroundJobsMode());
	}

	public function testStoredValuesAreClamped(): void {
		$store = FakeAppConfig::create($this, ['folderwatch/max_watches_per_user' => -4, 'folderwatch/max_sends_per_hour' => -9]);
		$settings = new AppSettings($store->config);
		$this->assertSame(1, $settings->getMaxWatchesPerUser());
		$this->assertSame(0, $settings->getMaxSendsPerHour());
		$this->assertInstanceOf(IAppConfig::class, $store->config);
	}
}
