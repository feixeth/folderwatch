<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Service\AppSettings;
use OCA\FolderWatch\Service\PathFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathFilterTest extends TestCase {
	private function filter(?string $configured = null): PathFilter {
		$settings = $this->createMock(AppSettings::class);
		$settings->method('getIgnoredPatterns')->willReturn(
			$configured === null ? AppSettings::DEFAULT_PATTERNS : AppSettings::parsePatterns($configured),
		);
		return new PathFilter($settings);
	}

	public static function trackedPaths(): array {
		return [
			'user files' => ['/alice/files/Docs/a.txt', true],
			'user root' => ['/alice/files', true],
			'shared view' => ['/bob/files/Docs/b.txt', true],
			'versions' => ['/alice/files_versions/Docs/a.txt.v123', false],
			'trash' => ['/alice/files_trashbin/files/a.txt.d123', false],
			'chunk uploads' => ['/alice/uploads/web-file-upload-1/00001', false],
			'app data' => ['/appdata_oc123/preview/1/256.png', false],
			'group folder internals' => ['/__groupfolders/1/a.txt', false],
			'folder that merely starts with files' => ['/alice/files_x/a.txt', false],
		];
	}

	#[DataProvider('trackedPaths')]
	public function testTrackedPaths(string $path, bool $expected): void {
		$this->assertSame($expected, $this->filter()->isTrackedPath($path));
	}

	public static function names(): array {
		return [
			['report.docx.part', true],
			['.~lock.report.docx#', true],
			['~$report.docx', true],
			['.DS_Store', true],
			['thumbs.db', true],
			['Thumbs.db', true],
			['report.docx', false],
			['part.txt', false],
			['my~$file', false],
		];
	}

	#[DataProvider('names')]
	public function testDefaultIgnoredNames(string $name, bool $ignored): void {
		$this->assertSame($ignored, $this->filter()->isIgnoredName($name));
	}

	public function testPatternsAreConfigurable(): void {
		$filter = $this->filter("*.tmp\n\n  draft-*  \n");
		$this->assertSame(['*.tmp', 'draft-*'], $filter->getPatterns());
		$this->assertTrue($filter->isIgnoredName('a.tmp'));
		$this->assertTrue($filter->isIgnoredName('draft-1.txt'));
		$this->assertFalse($filter->isIgnoredName('.DS_Store'));
	}

	public function testEmptyConfigurationIgnoresNothing(): void {
		$filter = $this->filter('');
		$this->assertFalse($filter->isIgnoredName('.DS_Store'));
	}
}
