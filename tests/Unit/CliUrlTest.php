<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Service\CliUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CliUrlTest extends TestCase {
	public static function usable(): array {
		return [
			'plain https' => ['https://cloud.example.org', 'https://cloud.example.org'],
			'trailing slash is dropped' => ['https://cloud.example.org/', 'https://cloud.example.org'],
			'sub-directory install' => ['https://example.org/nextcloud/', 'https://example.org/nextcloud'],
			'port' => ['http://cloud.example.org:8080', 'http://cloud.example.org:8080'],
			'whitespace' => ["  https://cloud.example.org \n", 'https://cloud.example.org'],
			'a public IP' => ['http://203.0.113.7', 'http://203.0.113.7'],
			'a name that merely contains localhost' => ['https://localhost-backup.example.org', 'https://localhost-backup.example.org'],
		];
	}

	#[DataProvider('usable')]
	public function testUsableAddresses(string $given, string $expected): void {
		$this->assertSame($expected, CliUrl::normalize($given));
	}

	public static function unusable(): array {
		return [
			'empty' => [''],
			'null' => [null],
			'blank' => ['   '],
			'localhost' => ['http://localhost'],
			'localhost with a port' => ['http://localhost:8080'],
			'localhost in capitals' => ['HTTP://LOCALHOST/'],
			'a .localhost name' => ['http://cloud.localhost'],
			'loopback' => ['http://127.0.0.1'],
			'loopback elsewhere in the range' => ['http://127.5.5.5:8000'],
			'IPv6 loopback' => ['http://[::1]:8080'],
			'any address' => ['http://0.0.0.0'],
			'no scheme' => ['cloud.example.org'],
			'not http' => ['ftp://cloud.example.org'],
			'garbage' => ['http:///'],
		];
	}

	#[DataProvider('unusable')]
	public function testUnusableAddresses(?string $given): void {
		$this->assertNull(CliUrl::normalize($given));
	}

	public static function hosts(): array {
		return [
			'same host' => ['https://cloud.example.org/index.php/f/1', 'https://cloud.example.org', true],
			'capitals do not matter' => ['https://Cloud.Example.ORG/f/1', 'https://cloud.example.org', true],
			'a sub-directory install' => ['https://example.org/nextcloud/index.php/f/1', 'https://example.org/nextcloud', true],
			'explicit default port' => ['https://cloud.example.org:443/f/1', 'https://cloud.example.org', true],
			'same explicit port' => ['http://cloud.example.org:8080/f/1', 'http://cloud.example.org:8080', true],
			'another port' => ['http://cloud.example.org:9090/f/1', 'http://cloud.example.org:8080', false],
			'another scheme means another port' => ['http://cloud.example.org/f/1', 'https://cloud.example.org', false],
			'another host' => ['https://old.example.org/f/1', 'https://cloud.example.org', false],
			'an internal address' => ['http://192.168.1.10/f/1', 'https://cloud.example.org', false],
			'a sub-domain is another host' => ['https://sub.cloud.example.org/f/1', 'https://cloud.example.org', false],
			'a trusted domain without a port allows any port' => ['https://cloud.example.org:8443/f/1', 'cloud.example.org', true],
			'a trusted domain with a port' => ['https://cloud.example.org:8443/f/1', 'cloud.example.org:8443', true],
			'a trusted domain with another port' => ['https://cloud.example.org/f/1', 'cloud.example.org:8443', false],
			'a trusted domain, another host' => ['https://old.example.org/f/1', 'cloud.example.org', false],
			'garbage' => ['not a url', 'cloud.example.org', false],
			'empty reference' => ['https://cloud.example.org/f/1', '', false],
		];
	}

	#[DataProvider('hosts')]
	public function testSameHost(string $url, string $reference, bool $expected): void {
		$this->assertSame($expected, CliUrl::sameHost($url, $reference));
	}
}
