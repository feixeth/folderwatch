<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use InvalidArgumentException;
use OCA\FolderWatch\AppInfo\Application;
use OCA\FolderWatch\ResponseDefinitions;
use OCP\IAppConfig;

/**
 * Instance-wide settings (the admin section writes them).
 *
 * @psalm-import-type FolderWatchAdminSettings from ResponseDefinitions
 */
class AppSettings {
	public const DEFAULT_MAX_WATCHES_PER_USER = 50;
	public const DEFAULT_MAX_SENDS_PER_HOUR = 20;
	public const DEFAULT_PATTERNS = ['*.part', '.~lock.*', '~$*', '.DS_Store', 'Thumbs.db'];

	public const MAX_WATCHES_LIMIT = 1000;
	public const MAX_SENDS_LIMIT = 1000;
	public const MAX_PATTERNS = 100;
	public const MAX_PATTERN_LENGTH = 255;

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	public function isMailEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'mail_enabled', true);
	}

	public function getMaxWatchesPerUser(): int {
		return max(1, $this->appConfig->getValueInt(Application::APP_ID, 'max_watches_per_user', self::DEFAULT_MAX_WATCHES_PER_USER));
	}

	/**
	 * Alerts (a notification and/or a mail for one folder) a user may receive per hour. 0 = no limit.
	 */
	public function getMaxSendsPerHour(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, 'max_sends_per_hour', self::DEFAULT_MAX_SENDS_PER_HOUR));
	}

	/**
	 * File name patterns (fnmatch) that never raise an alert.
	 *
	 * @return list<string>
	 */
	public function getIgnoredPatterns(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'ignored_patterns', implode("\n", self::DEFAULT_PATTERNS));
		return self::parsePatterns($raw);
	}

	/**
	 * How the instance runs its background jobs: cron, webcron or ajax. Alerts are only
	 * reliable with cron.
	 */
	public function getBackgroundJobsMode(): string {
		return $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
	}

	/**
	 * @return FolderWatchAdminSettings
	 */
	public function toArray(): array {
		return [
			'mailEnabled' => $this->isMailEnabled(),
			'maxWatchesPerUser' => $this->getMaxWatchesPerUser(),
			'maxSendsPerHour' => $this->getMaxSendsPerHour(),
			'ignoredPatterns' => $this->getIgnoredPatterns(),
		];
	}

	/**
	 * Applies the given settings, all or none.
	 *
	 * @param array<string, mixed> $values mailEnabled, maxWatchesPerUser, maxSendsPerHour, ignoredPatterns
	 * @throws InvalidArgumentException naming the first invalid field
	 */
	public function update(array $values): void {
		$mail = $watches = $sends = $patterns = null;
		if (array_key_exists('mailEnabled', $values)) {
			$mail = is_bool($values['mailEnabled']) ? $values['mailEnabled'] : throw new InvalidArgumentException('mailEnabled');
		}
		if (array_key_exists('maxWatchesPerUser', $values)) {
			$watches = self::boundedInt($values['maxWatchesPerUser'], 1, self::MAX_WATCHES_LIMIT, 'maxWatchesPerUser');
		}
		if (array_key_exists('maxSendsPerHour', $values)) {
			$sends = self::boundedInt($values['maxSendsPerHour'], 0, self::MAX_SENDS_LIMIT, 'maxSendsPerHour');
		}
		if (array_key_exists('ignoredPatterns', $values)) {
			$patterns = $this->validPatterns($values['ignoredPatterns']);
		}

		if ($mail !== null) {
			$this->appConfig->setValueBool(Application::APP_ID, 'mail_enabled', $mail);
		}
		if ($watches !== null) {
			$this->appConfig->setValueInt(Application::APP_ID, 'max_watches_per_user', $watches);
		}
		if ($sends !== null) {
			$this->appConfig->setValueInt(Application::APP_ID, 'max_sends_per_hour', $sends);
		}
		if ($patterns !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'ignored_patterns', implode("\n", $patterns));
		}
	}

	/**
	 * @return list<string>
	 */
	public static function parsePatterns(string $raw): array {
		return array_values(array_filter(
			array_map('trim', preg_split('/\R/', $raw) ?: []),
			static fn (string $pattern): bool => $pattern !== '',
		));
	}

	private static function boundedInt(mixed $value, int $min, int $max, string $field): int {
		if (!is_int($value) || $value < $min || $value > $max) {
			throw new InvalidArgumentException($field);
		}
		return $value;
	}

	/**
	 * @return list<string>
	 */
	private function validPatterns(mixed $value): array {
		if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_PATTERNS) {
			throw new InvalidArgumentException('ignoredPatterns');
		}
		$patterns = [];
		foreach ($value as $pattern) {
			if (!is_string($pattern) || mb_strlen($pattern) > self::MAX_PATTERN_LENGTH || preg_match('/[\r\n\0]/', $pattern) === 1) {
				throw new InvalidArgumentException('ignoredPatterns');
			}
			$pattern = trim($pattern);
			if ($pattern !== '') {
				$patterns[] = $pattern;
			}
		}
		return array_values(array_unique($patterns));
	}
}
