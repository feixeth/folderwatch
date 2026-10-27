<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCP\IL10N;

/**
 * English source strings, or the French file when $french is set: the real translation
 * behaviour (vsprintf, %n) without a Nextcloud.
 */
final class FakeL10N implements IL10N {
	/** @var array<string, string|list<string>> */
	private array $translations = [];
	private bool $french;

	public function __construct(bool $french = false) {
		$this->french = $french;
		if ($french) {
			$data = json_decode((string)file_get_contents(__DIR__ . '/../../l10n/fr.json'), true, 512, JSON_THROW_ON_ERROR);
			$this->translations = $data['translations'];
		}
	}

	public function t(string $text, $parameters = []): string {
		$text = $this->translations[$text] ?? $text;
		return vsprintf(is_array($text) ? $text[0] : $text, (array)$parameters);
	}

	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		// Nextcloud stores plural translations under "_singular_::_plural_"
		$forms = $this->translations['_' . $text_singular . '_::_' . $text_plural . '_'] ?? [$text_singular, $text_plural];
		// French: plural from 2 (0 and 1 are singular); English: everything but 1
		$plural = $this->french ? $count > 1 : $count !== 1;
		$text = $forms[$plural ? 1 : 0];
		return vsprintf(str_replace('%n', (string)$count, $text), $parameters);
	}

	public function l(string $type, $data, array $options = []) {
		return (string)$data;
	}

	public function getLanguageCode(): string {
		return $this->french ? 'fr' : 'en';
	}

	public function getLocaleCode(): string {
		return $this->getLanguageCode();
	}
}
