<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

/**
 * May this instance send an alert by e-mail at all, and if not, why? The administrator switch and
 * the mail server of Nextcloud decide; whether one user has an address is a matter for that user.
 */
class MailPolicy {
	public const REASON_DISABLED = 'disabled';
	public const REASON_NOT_CONFIGURED = 'not_configured';
	public const REASON_NO_ADDRESS = 'no_address';

	public function __construct(
		private readonly AppSettings $settings,
		private readonly InstanceHealth $health,
	) {
	}

	public function isAllowed(): bool {
		return $this->settings->isMailEnabled() && $this->health->isMailConfigured();
	}

	/**
	 * @param ?string $address the e-mail address of the user, if any
	 * @return ?string null when e-mail is available to this user
	 */
	public function unavailableReason(?string $address): ?string {
		if (!$this->settings->isMailEnabled()) {
			return self::REASON_DISABLED;
		}
		if (!$this->health->isMailConfigured()) {
			return self::REASON_NOT_CONFIGURED;
		}
		return ($address ?? '') === '' ? self::REASON_NO_ADDRESS : null;
	}
}
