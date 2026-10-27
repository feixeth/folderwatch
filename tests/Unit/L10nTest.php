<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every string passed to t() / n() in lib/ must have a French translation, and fr.js must
 * be the generated twin of fr.json (python3 tools/gen_l10n.py).
 */
class L10nTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	/** @return array<string, string|list<string>> */
	private function translations(): array {
		$data = json_decode((string)file_get_contents(self::ROOT . '/l10n/fr.json'), true, 512, JSON_THROW_ON_ERROR);
		return $data['translations'];
	}

	/** @return list<string> */
	private function sourceStrings(): array {
		$strings = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/lib'));
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$code = (string)file_get_contents($file->getPathname());
			// t('text' ...) is keyed by its text, n('singular', 'plural' ...) by "_singular_::_plural_"
			if (preg_match_all("/->t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $matches)) {
				foreach ($matches[1] as $string) {
					$strings[] = stripcslashes($string);
				}
			}
			if (preg_match_all("/->n\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$strings[] = '_' . stripcslashes($match[1]) . '_::_' . stripcslashes($match[2]) . '_';
				}
			}
		}

		// the frontend: t('text') in .ts and .vue files (same keys, {placeholders})
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src'));
		foreach ($iterator as $file) {
			if (!in_array($file->getExtension(), ['ts', 'vue'], true)) {
				continue;
			}
			$code = (string)file_get_contents($file->getPathname());
			if (preg_match_all("/(?<![\\w>.])t\\(\\s*(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")/", $code, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$strings[] = stripcslashes($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
				}
			}
		}
		return array_values(array_unique(array_filter($strings, static fn (string $s): bool => $s !== '')));
	}

	public function testEveryStringIsTranslated(): void {
		$translations = $this->translations();
		$missing = array_values(array_filter($this->sourceStrings(), static fn (string $s): bool => !isset($translations[$s])));
		$this->assertSame([], $missing, 'Missing in l10n/fr.json');
	}

	public function testNoTranslationIsLeftOver(): void {
		$sources = array_flip($this->sourceStrings());
		$unused = array_values(array_filter(array_keys($this->translations()), static fn (string $k): bool => !isset($sources[$k])));
		$this->assertSame([], $unused, 'In l10n/fr.json but not used in lib/');
	}

	public function testPluralsHaveTwoForms(): void {
		foreach ($this->translations() as $key => $value) {
			if (is_array($value)) {
				$this->assertCount(2, $value, $key);
				$this->assertStringStartsWith('_', $key);
				$this->assertStringContainsString('_::_', $key);
				$this->assertStringContainsString('%n', $key);
			}
		}
	}

	public function testPlaceholdersSurviveTranslation(): void {
		$pattern = '/\{[a-z]+\}|%(?:\d\$)?[sd]/';
		foreach ($this->translations() as $key => $value) {
			// a plural key is "_singular_::_plural_": each form is checked against its own source
			$sources = is_array($value) ? explode('_::_', substr($key, 1, -1)) : [$key];
			foreach ((array)$value as $i => $translation) {
				preg_match_all($pattern, $sources[$i], $expected);
				preg_match_all($pattern, $translation, $actual);
				sort($expected[0]);
				sort($actual[0]);
				$this->assertSame($expected[0], $actual[0], $key);
			}
		}
	}

	public function testJsTwinIsUpToDate(): void {
		$before = (string)file_get_contents(self::ROOT . '/l10n/fr.js');
		$expected = ['OC.L10N.register('];
		$this->assertStringStartsWith($expected[0], $before);
		foreach (array_keys($this->translations()) as $key) {
			$this->assertStringContainsString(json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $before, $key);
		}
	}
}
