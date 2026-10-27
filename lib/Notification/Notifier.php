<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Notification;

use OCA\FolderWatch\Alert\AlertSummary;
use OCA\FolderWatch\Alert\SummaryPresenter;
use OCA\FolderWatch\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Folder Watch');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'batch') {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$presenter = new SummaryPresenter($l);
		$summary = AlertSummary::fromArray($notification->getSubjectParameters())->withUrls($this->linkTo(...));

		[$subject, $objects] = $presenter->richSubject($summary);
		$notification->setRichSubject($subject, $objects)
			->setParsedSubject($presenter->plainSubject($summary))
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));

		$breakdown = $presenter->breakdown($summary);
		if ($breakdown !== '') {
			$notification->setParsedMessage($breakdown);
		}

		$url = $summary->getPrimaryUrl();
		if ($url !== null) {
			$notification->setLink($url);
			$open = $notification->createAction();
			$open->setParsedLabel($l->t('Open'))->setLink($url, IAction::TYPE_WEB)->setPrimary(true);
			$notification->addParsedAction($open);
		}
		return $notification;
	}

	private function linkTo(int $fileId): ?string {
		return $this->urlGenerator->linkToRouteAbsolute('files.view.showFile', ['fileid' => $fileId]);
	}
}
