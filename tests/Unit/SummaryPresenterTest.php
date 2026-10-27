<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Alert\AlertEntry;
use OCA\FolderWatch\Alert\AlertSummary;
use OCA\FolderWatch\Alert\SummaryPresenter;
use OCA\FolderWatch\Db\WatchOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SummaryPresenterTest extends TestCase {
	private const FOLDER = ['id' => 7, 'name' => 'À traiter', 'path' => '/Documents/À traiter', 'url' => 'https://nc.test/f/7'];

	/**
	 * @param list<array{int, ?string}> $events file ID => event, actor
	 */
	private function summary(array $events): AlertSummary {
		$entries = [];
		foreach ($events as $i => [$event, $actor]) {
			$entries[] = new AlertEntry(100 + $i, $event, $actor, "f$i.pdf", 1000 + $i, 'https://nc.test/f/' . (100 + $i));
		}
		return AlertSummary::fromEntries(
			$entries,
			self::FOLDER,
			static fn (string $uid): string => ucfirst($uid),
		);
	}

	private function many(int $n, int $event = WatchOptions::EVENT_CREATED, ?string $actor = 'alice'): AlertSummary {
		return $this->summary(array_fill(0, $n, [$event, $actor]));
	}

	public static function englishSubjects(): array {
		$c = WatchOptions::EVENT_CREATED;
		$m = WatchOptions::EVENT_MODIFIED;
		$d = WatchOptions::EVENT_DELETED;
		$i = WatchOptions::EVENT_MOVED_IN;
		return [
			'several new items by one person' => [[[$c, 'alice'], [$c, 'alice'], [$c, 'alice']], '3 new items in À traiter by Alice'],
			'several new items by several people' => [[[$c, 'alice'], [$c, 'bob']], '2 new items in À traiter'],
			'new items by a guest' => [[[$c, null], [$c, null]], '2 new items in À traiter'],
			'new items by a person and a guest' => [[[$c, 'alice'], [$c, null]], '2 new items in À traiter'],
			'moved in count as new' => [[[$c, 'alice'], [$i, 'alice']], '2 new items in À traiter by Alice'],
			'modifications' => [[[$m, 'alice'], [$m, 'alice']], '2 items modified in À traiter by Alice'],
			'removals' => [[[$d, 'alice'], [$d, 'alice']], '2 items removed from À traiter by Alice'],
			'mixed' => [[[$c, 'alice'], [$m, 'alice'], [$d, 'alice']], '3 changes in À traiter by Alice'],
			'one file added' => [[[$c, 'alice']], 'Alice added f0.pdf in À traiter'],
			'one file added by a guest' => [[[$c, null]], 'f0.pdf was added in À traiter'],
			'one file moved in' => [[[$i, 'alice']], 'Alice moved f0.pdf into À traiter'],
			'one file modified' => [[[$m, 'alice']], 'Alice modified f0.pdf in À traiter'],
			'one file removed' => [[[$d, 'alice']], 'Alice removed f0.pdf from À traiter'],
		];
	}

	#[DataProvider('englishSubjects')]
	public function testEnglishSubjects(array $events, string $expected): void {
		$presenter = new SummaryPresenter(new FakeL10N());
		$this->assertSame($expected, $presenter->plainSubject($this->summary($events)));
	}

	public function testTheExampleOfTheSpecInFrench(): void {
		$presenter = new SummaryPresenter(new FakeL10N(true));
		$this->assertSame('3 nouveaux éléments dans À traiter par Alice', $presenter->plainSubject($this->many(3)));
		$this->assertSame('Alice a ajouté f0.pdf dans À traiter', $presenter->plainSubject($this->many(1)));
		$this->assertSame('2 éléments supprimés de À traiter par Alice', $presenter->plainSubject($this->many(2, WatchOptions::EVENT_DELETED)));
	}

	public function testRichSubjectCarriesObjectsNotNames(): void {
		[$subject, $objects] = (new SummaryPresenter(new FakeL10N()))->richSubject($this->many(3));
		$this->assertSame('3 new items in {folder} by {user}', $subject);
		$this->assertSame('file', $objects['folder']['type']);
		$this->assertSame('7', $objects['folder']['id']);
		$this->assertSame('https://nc.test/f/7', $objects['folder']['link']);
		$this->assertSame(['type' => 'user', 'id' => 'alice', 'name' => 'Alice'], $objects['user']);
		$this->assertArrayNotHasKey('file', $objects);
	}

	public function testEveryPlaceholderOfTheRichSubjectHasAnObject(): void {
		$presenter = new SummaryPresenter(new FakeL10N());
		foreach (self::englishSubjects() as [$events]) {
			[$subject, $objects] = $presenter->richSubject($this->summary($events));
			preg_match_all('/\{([a-z]+)\}/', $subject, $found);
			foreach ($found[1] as $placeholder) {
				$this->assertArrayHasKey($placeholder, $objects, $subject);
			}
		}
	}

	public function testTheRootFolderHasAReadableName(): void {
		$summary = AlertSummary::fromEntries(
			[new AlertEntry(1, WatchOptions::EVENT_CREATED, 'alice', 'a.pdf', 1)],
			['id' => 5, 'name' => '/', 'path' => '/', 'url' => 'https://nc.test/f/5'],
			static fn (string $uid): string => ucfirst($uid),
		);
		$this->assertSame('Alice added a.pdf in All files', (new SummaryPresenter(new FakeL10N()))->plainSubject($summary));
		$this->assertSame('Alice a ajouté a.pdf dans Tous les fichiers', (new SummaryPresenter(new FakeL10N(true)))->plainSubject($summary));
	}

	public function testEveryObjectCarriesTheKeysNextcloudRequiresOfItsType(): void {
		$definitions = new \OCP\RichObjectStrings\Definitions();
		$presenter = new SummaryPresenter(new FakeL10N());
		foreach (self::englishSubjects() as $name => [$events]) {
			[, $objects] = $presenter->richSubject($this->summary($events));
			foreach ($objects as $placeholder => $object) {
				$required = array_keys(array_filter($definitions->getDefinition($object['type'])['parameters'], static fn (array $parameter): bool => $parameter['required']));
				$this->assertSame([], array_values(array_diff($required, array_keys($object))), "$name: {$placeholder} ({$object['type']}) lacks a required key");
				foreach ($object as $key => $value) {
					$this->assertIsString($value, "$name: $placeholder.$key must be a string");
				}
			}
		}
	}

	public function testARemovedFileIsHighlightedNotLinked(): void {
		[, $objects] = (new SummaryPresenter(new FakeL10N()))->richSubject($this->many(1, WatchOptions::EVENT_DELETED));
		$this->assertSame('highlight', $objects['file']['type']);
		$this->assertArrayNotHasKey('link', $objects['file']);
	}

	public function testBreakdownOnlyForMixedBatches(): void {
		$presenter = new SummaryPresenter(new FakeL10N());
		$this->assertSame('', $presenter->breakdown($this->many(3)));
		$this->assertSame('', $presenter->breakdown($this->many(1)));
		$mixed = $this->summary([
			[WatchOptions::EVENT_CREATED, 'alice'], [WatchOptions::EVENT_MOVED_IN, 'alice'],
			[WatchOptions::EVENT_MODIFIED, 'alice'], [WatchOptions::EVENT_DELETED, 'alice'],
		]);
		$this->assertSame('2 added, 1 modified, 1 removed', $presenter->breakdown($mixed));
		$this->assertSame('2 ajoutés, 1 modifié, 1 supprimé', (new SummaryPresenter(new FakeL10N(true)))->breakdown($mixed));
	}

	public function testPrimaryUrlIsTheFileOnlyWhenThereIsOne(): void {
		$this->assertSame('https://nc.test/f/100', $this->many(1)->getPrimaryUrl());
		$this->assertSame('https://nc.test/f/7', $this->many(2)->getPrimaryUrl());
		$this->assertSame('https://nc.test/f/7', $this->many(1, WatchOptions::EVENT_DELETED)->getPrimaryUrl());
	}

	public function testSummaryRoundTripsThroughANotification(): void {
		$summary = $this->summary([[WatchOptions::EVENT_CREATED, 'alice'], [WatchOptions::EVENT_MODIFIED, null]]);
		$copy = AlertSummary::fromArray(json_decode(json_encode($summary->toArray(), JSON_THROW_ON_ERROR), true));
		$this->assertEquals($summary, $copy);
	}

	public function testOnlyThreeActorsAreKept(): void {
		$summary = $this->summary([
			[WatchOptions::EVENT_CREATED, 'a'], [WatchOptions::EVENT_CREATED, 'b'],
			[WatchOptions::EVENT_CREATED, 'c'], [WatchOptions::EVENT_CREATED, 'd'],
		]);
		$this->assertCount(3, $summary->actors);
	}

	public function testVerbs(): void {
		$presenter = new SummaryPresenter(new FakeL10N(true));
		$this->assertSame('Ajouté', $presenter->verb(WatchOptions::EVENT_CREATED));
		$this->assertSame('Déplacé ici', $presenter->verb(WatchOptions::EVENT_MOVED_IN));
		$this->assertSame('Supprimé', $presenter->verb(WatchOptions::EVENT_DELETED));
		$this->assertSame('Modifié', $presenter->verb(WatchOptions::EVENT_MODIFIED));
	}

	public function testWithoutAnyLinkTheObjectsCarryNone(): void {
		$summary = $this->many(1)->withoutUrls();
		[, $objects] = (new SummaryPresenter(new FakeL10N()))->richSubject($summary);
		$this->assertArrayNotHasKey('link', $objects['folder']);
		$this->assertArrayNotHasKey('link', $objects['file']);
		$this->assertNull($summary->getPrimaryUrl());
	}

	public function testLinksCanBeAddedAtShowingTime(): void {
		$summary = $this->many(1)->withoutUrls()->withUrls(static fn (int $id): string => "https://shown.test/f/$id");
		$this->assertSame('https://shown.test/f/7', $summary->folder['url']);
		$this->assertSame('https://shown.test/f/100', $summary->file['url']);
		$this->assertSame('https://shown.test/f/100', $summary->getPrimaryUrl());
		[, $objects] = (new SummaryPresenter(new FakeL10N()))->richSubject($summary);
		$this->assertSame('https://shown.test/f/7', $objects['folder']['link']);
	}

	public function testARemovalLinksToTheFolderWhenLinksAreAdded(): void {
		$summary = $this->many(1, WatchOptions::EVENT_DELETED)->withoutUrls()->withUrls(static fn (int $id): string => "https://shown.test/f/$id");
		$this->assertSame('https://shown.test/f/7', $summary->file['url']);
	}

	public function testTheSummaryKeepsTheLinksOfTheStoredEntries(): void {
		$this->assertSame('https://nc.test/f/100', $this->many(1)->file['url']);
		$entry = new AlertEntry(5, WatchOptions::EVENT_CREATED, 'alice', 'x.pdf', 1);
		$summary = AlertSummary::fromEntries([$entry], self::FOLDER, static fn (string $uid): string => $uid);
		$this->assertNull($summary->file['url'], 'an entry without a link has none');
	}
}
