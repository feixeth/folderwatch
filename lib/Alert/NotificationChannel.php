<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

use DateTime;
use OCA\FolderWatch\AppInfo\Application;
use OCA\FolderWatch\Db\Watch;
use OCP\Notification\IManager;

class NotificationChannel {
	public function __construct(
		private readonly IManager $manager,
	) {
	}

	public function send(Watch $watch, AlertSummary $summary, int $time): void {
		$notification = $this->manager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($watch->getUserId())
			->setDateTime((new DateTime())->setTimestamp($time))
			->setObject('watch', (string)$watch->getId())
			// no links here: they are made by the Notifier when the notification is shown, in the web
			// request of the reader, so they never depend on the configuration of the server
			->setSubject('batch', $summary->withoutUrls()->toArray());

		$this->manager->notify($notification);
	}
}
