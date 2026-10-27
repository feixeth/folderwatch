<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use Exception;
use OCP\AppFramework\Http;

/**
 * Business error carrying a translated, user-facing message and the HTTP status to answer with.
 */
class WatchException extends Exception {
	/**
	 * @param Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT $status
	 */
	public function __construct(
		string $message,
		private readonly int $status = Http::STATUS_BAD_REQUEST,
	) {
		parent::__construct($message, $status);
	}

	/**
	 * @return Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT
	 */
	public function getStatus(): int {
		return $this->status;
	}
}
