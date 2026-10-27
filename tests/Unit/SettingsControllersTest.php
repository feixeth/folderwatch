<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Controller\AdminSettingsController;
use OCA\FolderWatch\Controller\UserSettingsController;
use OCA\FolderWatch\Service\AppSettings;
use OCA\FolderWatch\Service\UserSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SettingsControllersTest extends TestCase {
	private function admin(?FakeAppConfig $store = null): AdminSettingsController {
		$store ??= FakeAppConfig::create($this);
		return new AdminSettingsController('folderwatch', $this->createMock(IRequest::class), new AppSettings($store->config), new FakeL10N());
	}

	public function testAdminCanReadTheSettings(): void {
		$response = $this->admin()->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(50, $response->getData()['maxWatchesPerUser']);
	}

	public function testAdminSavesOnlyWhatIsSent(): void {
		$store = FakeAppConfig::create($this);
		$response = $this->admin($store)->update(mailEnabled: false, ignoredPatterns: ['*.tmp']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['mailEnabled']);
		$this->assertSame(['*.tmp'], $response->getData()['ignoredPatterns']);
		$this->assertSame(20, $response->getData()['maxSendsPerHour']);
		$this->assertSame(['folderwatch/mail_enabled', 'folderwatch/ignored_patterns'], array_keys($store->values));
	}

	public function testAdminGetsAMessageForAnInvalidValueAndNothingIsSaved(): void {
		$store = FakeAppConfig::create($this);
		$response = $this->admin($store)->update(maxWatchesPerUser: 5000);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid value for "maxWatchesPerUser".', $response->getData()['message']);
		$this->assertSame([], $store->values);
	}

	public function testAdminEndpointsAreNotOpenToOrdinaryUsers(): void {
		foreach (['index', 'update'] as $method) {
			$attributes = (new \ReflectionMethod(AdminSettingsController::class, $method))->getAttributes(NoAdminRequired::class);
			$this->assertSame([], $attributes, "$method must require an administrator");
		}
	}

	public function testUserEndpointsAreOpenToOrdinaryUsers(): void {
		foreach (['index', 'update'] as $method) {
			$attributes = (new \ReflectionMethod(UserSettingsController::class, $method))->getAttributes(NoAdminRequired::class);
			$this->assertCount(1, $attributes, $method);
		}
	}

	public function testUserSettingIsStoredPerUser(): void {
		$values = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(static fn (string $uid, string $app, string $key, string $default): string => $values[$uid . $key] ?? $default);
		$config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, string $value) use (&$values): void {
			$values[$uid . $key] = $value;
		});
		$settings = new UserSettings($config);
		$alice = new UserSettingsController('folderwatch', $this->createMock(IRequest::class), $settings, 'alice');
		$bob = new UserSettingsController('folderwatch', $this->createMock(IRequest::class), $settings, 'bob');

		$this->assertTrue($alice->index()->getData()['ignoreOwnActions'], 'on by default');
		$this->assertFalse($alice->update(false)->getData()['ignoreOwnActions']);
		$this->assertTrue($bob->index()->getData()['ignoreOwnActions']);
		$this->assertSame('no', $values['aliceignore_own']);
	}

	public function testUserUpdateWithNothingChangesNothing(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('yes');
		$config->expects($this->never())->method('setUserValue');
		$controller = new UserSettingsController('folderwatch', $this->createMock(IRequest::class), new UserSettings($config), 'alice');
		$this->assertTrue($controller->update()->getData()['ignoreOwnActions']);
	}
}
