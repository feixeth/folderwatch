<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Db;

use InvalidArgumentException;

/**
 * Bitmask values stored in the database and their names exposed by the API.
 */
final class WatchOptions {
	public const EVENT_CREATED = 1;
	public const EVENT_MODIFIED = 2;
	public const EVENT_DELETED = 4;
	public const EVENT_MOVED_IN = 8;

	/** An incoming move is a creation from the folder's point of view, so it is part of the default. */
	public const DEFAULT_EVENTS = self::EVENT_CREATED | self::EVENT_MOVED_IN;

	public const CHANNEL_NOTIFICATION = 1;
	public const CHANNEL_MAIL = 2;

	public const FREQUENCY_IMMEDIATE = 'immediate';
	public const FREQUENCY_HOURLY = 'hourly';
	public const FREQUENCY_DAILY = 'daily';

	public const EVENTS = [
		'created' => self::EVENT_CREATED,
		'modified' => self::EVENT_MODIFIED,
		'deleted' => self::EVENT_DELETED,
		'moved_in' => self::EVENT_MOVED_IN,
	];

	public const CHANNELS = [
		'notification' => self::CHANNEL_NOTIFICATION,
		'mail' => self::CHANNEL_MAIL,
	];

	public const FREQUENCIES = [
		self::FREQUENCY_IMMEDIATE,
		self::FREQUENCY_HOURLY,
		self::FREQUENCY_DAILY,
	];

	/**
	 * @param list<string> $names
	 * @param array<string, int> $map
	 * @throws InvalidArgumentException on an unknown name or an empty selection
	 */
	public static function maskFromNames(array $names, array $map): int {
		$mask = 0;
		foreach ($names as $name) {
			if (!is_string($name) || !isset($map[$name])) {
				throw new InvalidArgumentException('Unknown value: ' . (is_scalar($name) ? (string)$name : gettype($name)));
			}
			$mask |= $map[$name];
		}
		if ($mask === 0) {
			throw new InvalidArgumentException('At least one value is required');
		}
		return $mask;
	}

	/**
	 * @param array<string, int> $map
	 * @return list<string>
	 */
	public static function namesFromMask(int $mask, array $map): array {
		$names = [];
		foreach ($map as $name => $bit) {
			if (($mask & $bit) === $bit) {
				$names[] = $name;
			}
		}
		return $names;
	}
}
