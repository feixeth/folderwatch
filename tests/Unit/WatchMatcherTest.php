<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Service\AccessChecker;
use OCA\FolderWatch\Service\NodeLocation;
use OCA\FolderWatch\Service\UserSettings;
use OCA\FolderWatch\Service\WatchMatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WatchMatcherTest extends TestCase {
	private WatchMapper&MockObject $mapper;
	private AccessChecker&MockObject $access;
	private UserSettings&MockObject $settings;
	private WatchMatcher $matcher;

	protected function setUp(): void {
		$this->mapper = $this->createMock(WatchMapper::class);
		$this->access = $this->createMock(AccessChecker::class);
		$this->access->method('canAccess')->willReturn(true);
		$this->settings = $this->createMock(UserSettings::class);
		$this->settings->method('ignoreOwnActions')->willReturn(true);
		$this->matcher = new WatchMatcher($this->mapper, $this->access, $this->settings);
	}

	private function location(): NodeLocation {
		return new NodeLocation(30, [30, 20, 10], [30 => 'files/Docs/Sub', 20 => 'files/Docs', 10 => 'files']);
	}

	private function candidates(array $watches): void {
		$this->mapper->method('findCandidates')->willReturn($watches);
	}

	/** @return list<int> IDs of the matched watches */
	private function ids(array $watches): array {
		return array_map(static fn ($watch): int => $watch->getId(), $watches);
	}

	public function testCandidatesAreLookedUpByFolderAndChain(): void {
		$this->mapper->expects($this->once())->method('findCandidates')->with(30, [30, 20, 10])->willReturn([]);
		$this->matcher->match($this->location(), WatchOptions::EVENT_CREATED, 'bob');
	}

	public function testWatchIsMatchedWhenItSubscribesToTheEvent(): void {
		$this->candidates([
			WatchFactory::make(1, 'alice', 20, true, WatchOptions::EVENT_CREATED),
			WatchFactory::make(2, 'carol', 20, true, WatchOptions::EVENT_MODIFIED),
		]);
		$this->assertSame([1], $this->ids($this->matcher->match($this->location(), WatchOptions::EVENT_CREATED, 'bob')));
	}

	public function testOwnActionsAreIgnoredWhenTheOptionIsOn(): void {
		$this->candidates([WatchFactory::make(1, 'alice', 20), WatchFactory::make(2, 'bob', 20)]);
		$this->assertSame([2], $this->ids($this->matcher->match($this->location(), WatchOptions::EVENT_CREATED, 'alice')));
	}

	public function testOwnActionsAreReportedWhenTheOptionIsOff(): void {
		$settings = $this->createMock(UserSettings::class);
		$settings->method('ignoreOwnActions')->willReturn(false);
		$matcher = new WatchMatcher($this->mapper, $this->access, $settings);
		$this->candidates([WatchFactory::make(1, 'alice', 20)]);
		$this->assertSame([1], $this->ids($matcher->match($this->location(), WatchOptions::EVENT_CREATED, 'alice')));
	}

	public function testAGuestIsNeverTheWatcher(): void {
		$this->candidates([WatchFactory::make(1, 'alice', 20)]);
		$this->assertSame([1], $this->ids($this->matcher->match($this->location(), WatchOptions::EVENT_CREATED, null)));
	}

	public function testWatchWhoseOwnerLostAccessIsSkipped(): void {
		$access = $this->createMock(AccessChecker::class);
		$access->method('canAccess')->willReturnCallback(static fn (string $uid, int $fileId): bool => !($uid === 'carol' && $fileId === 20));
		$matcher = new WatchMatcher($this->mapper, $access, $this->settings);
		$this->candidates([WatchFactory::make(1, 'alice', 20), WatchFactory::make(2, 'carol', 20)]);
		$this->assertSame([1], $this->ids($matcher->match($this->location(), WatchOptions::EVENT_CREATED, 'bob')));
	}

	public function testWatcherMustAlsoSeeTheFolderWhereItHappened(): void {
		// e.g. a group folder ACL hides Sub from carol although she can open Docs
		$access = $this->createMock(AccessChecker::class);
		$access->method('canAccess')->willReturnCallback(static fn (string $uid, int $fileId): bool => !($uid === 'carol' && $fileId === 30));
		$matcher = new WatchMatcher($this->mapper, $access, $this->settings);
		$this->candidates([WatchFactory::make(1, 'alice', 20), WatchFactory::make(2, 'carol', 20)]);
		$this->assertSame([1], $this->ids($matcher->match($this->location(), WatchOptions::EVENT_CREATED, 'bob')));
	}

	public function testAccessToTheDirectParentIsCheckedOnlyOnce(): void {
		$access = $this->createMock(AccessChecker::class);
		$access->expects($this->once())->method('canAccess')->with('alice', 30)->willReturn(true);
		$matcher = new WatchMatcher($this->mapper, $access, $this->settings);
		$this->candidates([WatchFactory::make(1, 'alice', 30)]);
		$matcher->match($this->location(), WatchOptions::EVENT_CREATED, 'bob');
	}

	public function testWatchesCoveringTheOtherLocationAreExcluded(): void {
		// moving inside a watched tree: the recursive watch on Docs does not see an incoming file
		$from = new NodeLocation(31, [31, 20, 10], [31 => 'files/Docs/Other', 20 => 'files/Docs', 10 => 'files']);
		$this->candidates([
			WatchFactory::make(1, 'alice', 20, true),
			WatchFactory::make(2, 'carol', 30, false),
			WatchFactory::make(3, 'dave', 10, false),
		]);
		$matched = $this->matcher->match($this->location(), WatchOptions::EVENT_MOVED_IN, 'bob', $from);
		// 1 covers the source (recursive), 2 does not, 3 is not recursive so it does not cover the source
		$this->assertSame([2, 3], $this->ids($matched));
	}

	public function testCandidatesAreLoadedOncePerFolder(): void {
		$this->mapper->expects($this->once())->method('findCandidates')->willReturn([]);
		$this->matcher->match($this->location(), WatchOptions::EVENT_CREATED, 'bob');
		$this->matcher->match($this->location(), WatchOptions::EVENT_MODIFIED, 'bob');
	}
}
