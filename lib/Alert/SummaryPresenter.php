<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

use OCA\FolderWatch\Db\WatchOptions;
use OCP\IL10N;

/**
 * Words for an alert, as a rich text (notifications) or a plain one (mail subject and heading).
 */
class SummaryPresenter {
	public function __construct(
		private readonly IL10N $l,
	) {
	}

	/**
	 * @return array{0: string, 1: array<string, array<string, string>>} rich subject and its objects
	 */
	public function richSubject(AlertSummary $summary): array {
		return [$this->template($summary), $this->objects($summary)];
	}

	public function plainSubject(AlertSummary $summary): string {
		$replacements = [];
		foreach ($this->objects($summary) as $key => $object) {
			$replacements['{' . $key . '}'] = $object['name'];
		}
		return strtr($this->template($summary), $replacements);
	}

	/**
	 * What a mixed batch is made of, e.g. "2 added, 1 modified". Empty for a uniform batch.
	 */
	public function breakdown(AlertSummary $summary): string {
		$added = $summary->counts['created'] + $summary->counts['moved_in'];
		$modified = $summary->counts['modified'];
		$removed = $summary->counts['deleted'];
		if ($summary->total < 2 || count(array_filter([$added, $modified, $removed])) < 2) {
			return '';
		}

		$parts = [];
		if ($added > 0) {
			$parts[] = $this->l->n('%n added', '%n added', $added);
		}
		if ($modified > 0) {
			$parts[] = $this->l->n('%n modified', '%n modified', $modified);
		}
		if ($removed > 0) {
			$parts[] = $this->l->n('%n removed', '%n removed', $removed);
		}
		return implode(', ', $parts);
	}

	public function verb(int $event): string {
		return match ($event) {
			WatchOptions::EVENT_CREATED => $this->l->t('Added'),
			WatchOptions::EVENT_MOVED_IN => $this->l->t('Moved in'),
			WatchOptions::EVENT_DELETED => $this->l->t('Removed'),
			default => $this->l->t('Modified'),
		};
	}

	private function template(AlertSummary $summary): string {
		$byOne = count($summary->actors) === 1 && !$summary->unknownActor;
		return $summary->file !== null
			? $this->singleTemplate($summary->file['event'], $byOne)
			: $this->groupTemplate($summary, $byOne);
	}

	private function singleTemplate(int $event, bool $byOne): string {
		return match ($event) {
			WatchOptions::EVENT_CREATED => $byOne
				? $this->l->t('{user} added {file} in {folder}')
				: $this->l->t('{file} was added in {folder}'),
			WatchOptions::EVENT_MOVED_IN => $byOne
				? $this->l->t('{user} moved {file} into {folder}')
				: $this->l->t('{file} was moved into {folder}'),
			WatchOptions::EVENT_DELETED => $byOne
				? $this->l->t('{user} removed {file} from {folder}')
				: $this->l->t('{file} was removed from {folder}'),
			default => $byOne
				? $this->l->t('{user} modified {file} in {folder}')
				: $this->l->t('{file} was modified in {folder}'),
		};
	}

	private function groupTemplate(AlertSummary $summary, bool $byOne): string {
		$n = $summary->total;
		$added = $summary->counts['created'] + $summary->counts['moved_in'];
		if ($added === $n) {
			return $byOne
				? $this->l->n('%n new item in {folder} by {user}', '%n new items in {folder} by {user}', $n)
				: $this->l->n('%n new item in {folder}', '%n new items in {folder}', $n);
		}
		if ($summary->counts['modified'] === $n) {
			return $byOne
				? $this->l->n('%n item modified in {folder} by {user}', '%n items modified in {folder} by {user}', $n)
				: $this->l->n('%n item modified in {folder}', '%n items modified in {folder}', $n);
		}
		if ($summary->counts['deleted'] === $n) {
			return $byOne
				? $this->l->n('%n item removed from {folder} by {user}', '%n items removed from {folder} by {user}', $n)
				: $this->l->n('%n item removed from {folder}', '%n items removed from {folder}', $n);
		}
		return $byOne
			? $this->l->n('%n change in {folder} by {user}', '%n changes in {folder} by {user}', $n)
			: $this->l->n('%n change in {folder}', '%n changes in {folder}', $n);
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function objects(AlertSummary $summary): array {
		$folder = [
			'type' => 'file',
			'id' => (string)$summary->folder['id'],
			'name' => $summary->folder['path'] === '/' ? $this->l->t('All files') : $summary->folder['name'],
			'path' => $summary->folder['path'],
		];
		if ($summary->folder['url'] !== null) {
			$folder['link'] = $summary->folder['url'];
		}
		$objects = ['folder' => $folder];

		if (count($summary->actors) === 1 && !$summary->unknownActor) {
			$objects['user'] = ['type' => 'user', 'id' => $summary->actors[0]['id'], 'name' => $summary->actors[0]['name']];
		}
		if ($summary->file !== null) {
			if ($summary->file['event'] === WatchOptions::EVENT_DELETED) {
				$objects['file'] = ['type' => 'highlight', 'id' => (string)$summary->file['id'], 'name' => $summary->file['name']];
			} else {
				$file = ['type' => 'file', 'id' => (string)$summary->file['id'], 'name' => $summary->file['name'], 'path' => $summary->file['path']];
				if ($summary->file['url'] !== null) {
					$file['link'] = $summary->file['url'];
				}
				$objects['file'] = $file;
			}
		}
		return $objects;
	}
}
