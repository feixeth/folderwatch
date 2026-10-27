<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use Closure;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An IConfig that answers system values (config.php) from an array.
 */
final class FakeSystemConfig {
	/**
	 * @param array<string, mixed> $values
	 */
	public static function create(TestCase $test, array $values = []): IConfig&MockObject {
		/** @var IConfig&MockObject $config */
		$config = Closure::bind(fn () => $this->createMock(IConfig::class), $test, TestCase::class)();
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = '') => $values[$key] ?? $default);
		$config->method('getSystemValue')->willReturnCallback(static fn (string $key, mixed $default = '') => $values[$key] ?? $default);
		return $config;
	}

	/** A Nextcloud with a working mail server, as docker-compose.dev.yml sets it up. */
	public static function withMail(): array {
		return ['mail_smtpmode' => 'smtp', 'mail_smtphost' => 'smtp.example.org', 'mail_from_address' => 'cloud', 'mail_domain' => 'example.org'];
	}
}
