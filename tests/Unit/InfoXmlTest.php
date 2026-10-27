<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * appinfo/info.xml is read by Nextcloud, not by PHP: a typo only shows when somebody installs the app.
 */
class InfoXmlTest extends TestCase {
	private \SimpleXMLElement $info;

	protected function setUp(): void {
		$this->info = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml') ?: throw new \RuntimeException('info.xml is not valid XML');
	}

	/** @return list<string> */
	private function classes(string $xpath): array {
		return array_map(static fn (\SimpleXMLElement $node): string => trim((string)$node), $this->info->xpath($xpath) ?: []);
	}

	public function testDeclaredClassesExist(): void {
		$declared = [
			...$this->classes('//background-jobs/job'),
			...$this->classes('//commands/command'),
			...$this->classes('//settings/*'),
		];
		$this->assertNotEmpty($declared);
		foreach ($declared as $class) {
			$this->assertTrue(class_exists($class), "$class is declared in info.xml but does not exist");
		}
	}

	public function testBackgroundJobsAndCommandsAreTheOnesWeExpect(): void {
		$this->assertEqualsCanonicalizing([
			'OCA\FolderWatch\BackgroundJob\SendAlertsJob',
			'OCA\FolderWatch\BackgroundJob\CleanupJob',
		], $this->classes('//background-jobs/job'));

		$names = array_map(function (string $class): string {
			$arguments = array_map(
				fn (\ReflectionParameter $parameter): object => $this->createMock($parameter->getType()->getName()),
				(new \ReflectionClass($class))->getConstructor()->getParameters(),
			);
			return (new $class(...$arguments))->getName();
		}, $this->classes('//commands/command'));
		$this->assertEqualsCanonicalizing(['folderwatch:list', 'folderwatch:add', 'folderwatch:remove', 'folderwatch:flush'], $names);
	}

	/**
	 * Nextcloud runs the "uninstall" repair steps every time an app is DISABLED. A step that
	 * dropped our tables would wipe every watch when an admin switches the app off for a minute.
	 */
	public function testNoUninstallStepThatCouldDestroyDataOnDisable(): void {
		$this->assertSame([], $this->info->xpath('//repair-steps/uninstall') ?: []);
	}

	public function testVersionRangeAndLicence(): void {
		$nextcloud = $this->info->dependencies->nextcloud;
		$this->assertSame('33', (string)$nextcloud['min-version']);
		$this->assertSame('36', (string)$nextcloud['max-version']);
		$this->assertSame('agpl', (string)$this->info->licence);
		$this->assertSame('ZEDD', (string)$this->info->author);
		$this->assertSame('folderwatch', (string)$this->info->id);
	}
}
