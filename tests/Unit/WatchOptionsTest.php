<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use InvalidArgumentException;
use OCA\FolderWatch\Db\WatchOptions;
use PHPUnit\Framework\TestCase;

class WatchOptionsTest extends TestCase {
	public function testMaskFromNamesCombinesBits(): void {
		$mask = WatchOptions::maskFromNames(['created', 'moved_in'], WatchOptions::EVENTS);
		$this->assertSame(WatchOptions::EVENT_CREATED | WatchOptions::EVENT_MOVED_IN, $mask);
	}

	public function testNamesFromMaskIsTheInverse(): void {
		$names = ['notification', 'mail'];
		$mask = WatchOptions::maskFromNames($names, WatchOptions::CHANNELS);
		$this->assertSame(3, $mask);
		$this->assertSame($names, WatchOptions::namesFromMask($mask, WatchOptions::CHANNELS));
	}

	public function testUnknownNameIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		WatchOptions::maskFromNames(['created', 'bogus'], WatchOptions::EVENTS);
	}

	public function testEmptySelectionIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		WatchOptions::maskFromNames([], WatchOptions::CHANNELS);
	}

	public function testNonStringValueIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		WatchOptions::maskFromNames([1], WatchOptions::EVENTS);
	}
}
