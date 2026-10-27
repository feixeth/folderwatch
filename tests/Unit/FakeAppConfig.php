<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use Closure;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An IAppConfig that remembers what is written to it, like the real one.
 * Keys of $values are "app/key".
 */
final class FakeAppConfig {
	public IAppConfig&MockObject $config;

	/**
	 * @param array<string, mixed> $values
	 */
	private function __construct(
		TestCase $test,
		public array $values,
	) {
		/** @var IAppConfig&MockObject $config */
		$config = Closure::bind(fn () => $this->createMock(IAppConfig::class), $test, TestCase::class)();

		foreach (['getValueBool', 'getValueInt', 'getValueString'] as $method) {
			$config->method($method)->willReturnCallback(fn (string $app, string $key, $default = null) => $this->values[$app . '/' . $key] ?? $default);
		}
		foreach (['setValueBool', 'setValueInt', 'setValueString'] as $method) {
			$config->method($method)->willReturnCallback(function (string $app, string $key, $value): bool {
				$this->values[$app . '/' . $key] = $value;
				return true;
			});
		}
		$this->config = $config;
	}

	/**
	 * @param array<string, mixed> $initial
	 */
	public static function create(TestCase $test, array $initial = []): self {
		return new self($test, $initial);
	}
}
