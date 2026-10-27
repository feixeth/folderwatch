<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

/**
 * overwrite.cli.url: the address Nextcloud uses for links made outside a web request.
 */
final class CliUrl {
	/**
	 * The address without its trailing slash, or null when it is empty, malformed, or points at the
	 * machine itself (localhost, 127.0.0.1, ::1): such a link is useless to the person reading the mail.
	 */
	public static function normalize(?string $url): ?string {
		$url = trim((string)$url);
		if ($url === '') {
			return null;
		}
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			return null;
		}

		if (self::isLocalHost($parts['host'])) {
			return null;
		}
		return rtrim($url, '/');
	}

	/**
	 * Does this host name the machine itself (localhost, 127.x, ::1, 0.0.0.0)?
	 */
	public static function isLocalHost(string $host): bool {
		$host = strtolower(trim($host, '[]'));
		return $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1' || $host === '0.0.0.0' || preg_match('/^127\./', $host) === 1;
	}

	/**
	 * Is $url served from the host that $reference names? $reference is an address (with a scheme) or a
	 * trusted_domains entry (host, optionally with a port). A reference without a port matches any port,
	 * as Nextcloud itself reads trusted_domains; otherwise the ports must be the same, the default one of
	 * the scheme counting for an absent one.
	 */
	public static function sameHost(string $url, string $reference): bool {
		$own = self::hostAndPort($url);
		$other = self::hostAndPort($reference);
		if ($own === null || $other === null || $own[0] !== $other[0]) {
			return false;
		}
		return $other[1] === null || $own[1] === $other[1];
	}

	/**
	 * @return ?array{string, ?int} lower-case host and port (the default one of the scheme when it is left out of an address with a scheme)
	 */
	private static function hostAndPort(string $address): ?array {
		$address = trim($address);
		$hasScheme = str_contains($address, '://');
		$parts = parse_url($hasScheme ? $address : '//' . $address);
		if ($parts === false || !isset($parts['host'])) {
			return null;
		}
		$port = $parts['port'] ?? null;
		if ($port === null && $hasScheme) {
			$port = match (strtolower($parts['scheme'] ?? '')) {
				'https' => 443,
				'http' => 80,
				default => null,
			};
		}
		return [strtolower(trim($parts['host'], '[]')), $port];
	}
}
