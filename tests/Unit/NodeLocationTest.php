<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Service\NodeLocation;
use PHPUnit\Framework\TestCase;

class NodeLocationTest extends TestCase {
	private function location(): NodeLocation {
		// files/Docs/Sub/Deep, nearest first
		return new NodeLocation(40, [40, 30, 20, 10], [
			40 => 'files/Docs/Sub/Deep',
			30 => 'files/Docs/Sub',
			20 => 'files/Docs',
			10 => 'files',
		]);
	}

	public function testDirectParentIsCoveredByRecursiveAndFlatWatches(): void {
		$this->assertTrue($this->location()->isCoveredBy(40, false));
		$this->assertTrue($this->location()->isCoveredBy(40, true));
	}

	public function testAncestorIsCoveredOnlyWhenRecursive(): void {
		$this->assertTrue($this->location()->isCoveredBy(20, true));
		$this->assertFalse($this->location()->isCoveredBy(20, false));
	}

	public function testUnrelatedFolderIsNotCovered(): void {
		$this->assertFalse($this->location()->isCoveredBy(99, true));
	}

	public function testRelativePathIsComputedFromTheWatchedFolder(): void {
		$location = $this->location();
		$this->assertSame('a.txt', $location->relativePath(40, 'a.txt'));
		$this->assertSame('Deep/a.txt', $location->relativePath(30, 'a.txt'));
		$this->assertSame('Sub/Deep/a.txt', $location->relativePath(20, 'a.txt'));
		$this->assertSame('Docs/Sub/Deep/a.txt', $location->relativePath(10, 'a.txt'));
	}

	public function testRelativePathFallsBackToTheNameWhenPathsAreUnknown(): void {
		$this->assertSame('a.txt', $this->location()->relativePath(99, 'a.txt'));
	}

	public function testRelativePathFromTheStorageRoot(): void {
		$location = new NodeLocation(2, [2, 1], [2 => 'files', 1 => '']);
		$this->assertSame('files/a.txt', $location->relativePath(1, 'a.txt'));
	}
}
