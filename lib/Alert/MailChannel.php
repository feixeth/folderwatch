<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Alert;

use OCA\FolderWatch\AppInfo\Application;
use OCA\FolderWatch\Db\Watch;
use OCA\FolderWatch\Db\WatchOptions;
use OCP\Defaults;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\Headers\AutoSubmitted;
use OCP\Mail\IMailer;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * The e-mail only carries names and links, never the content of a file.
 */
class MailChannel {
	/** More than this many entries are summed up as "and N others". */
	public const MAX_LISTED = 50;

	public function __construct(
		private readonly IMailer $mailer,
		private readonly IUserManager $userManager,
		private readonly IFactory $l10nFactory,
		private readonly IDateTimeFormatter $dateFormatter,
		private readonly IDateTimeZone $dateTimeZone,
		private readonly Defaults $defaults,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<AlertEntry> $entries oldest first
	 * @return bool false when nothing was sent because the user has no usable address
	 * @throws \RuntimeException when the mail server refused the message
	 */
	public function send(Watch $watch, AlertSummary $summary, array $entries, int $time): bool {
		$user = $this->userManager->get($watch->getUserId());
		$address = $user?->getEMailAddress();
		if ($user === null || $address === null || $address === '' || !$this->mailer->validateMailAddress($address)) {
			$this->logger->debug('Folder Watch: no usable e-mail address for ' . $watch->getUserId() . ', mail skipped', ['app' => Application::APP_ID]);
			return false;
		}

		$lang = $this->l10nFactory->getUserLanguage($user);
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		$presenter = new SummaryPresenter($l);
		$subject = $presenter->plainSubject($summary);

		$template = $this->mailer->createEMailTemplate('folderwatch.alert', [
			'folder' => $summary->folder['path'],
			'total' => $summary->total,
		]);
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($subject);

		$breakdown = $presenter->breakdown($summary);
		if ($breakdown !== '') {
			$template->addBodyText($breakdown);
		}

		$timeZone = $this->dateTimeZone->getTimeZone($time, $watch->getUserId());
		foreach (array_slice($entries, 0, self::MAX_LISTED) as $entry) {
			$this->addEntry($template, $presenter, $l, $entry, $timeZone);
		}
		$others = count($entries) - self::MAX_LISTED;
		if ($others > 0) {
			$template->addBodyText($l->n('… and %n other item.', '… and %n other items.', $others));
		}

		if ($summary->folder['url'] !== null) {
			$template->addBodyButton($l->t('Open folder'), $summary->folder['url']);
		}
		$template->addFooter($l->t('You receive this e-mail because you watch the folder "%s". You can change this in your personal settings.', [$summary->folder['path']]), $lang);

		$message = $this->mailer->createMessage();
		$message->setFrom([Util::getDefaultEmailAddress(Application::APP_ID) => $this->defaults->getName()]);
		$message->setTo([$address => $user->getDisplayName()]);
		$message->setAutoSubmitted(AutoSubmitted::VALUE_AUTO_GENERATED);
		$message->useTemplate($template);

		$failed = $this->mailer->send($message);
		if ($failed !== []) {
			throw new \RuntimeException('The mail server refused: ' . implode(', ', $failed));
		}
		return true;
	}

	private function addEntry(\OCP\Mail\IEMailTemplate $template, SummaryPresenter $presenter, \OCP\IL10N $l, AlertEntry $entry, \DateTimeZone $timeZone): void {
		$name = $entry->pathHint !== '' ? $entry->pathHint : (string)$entry->fileId;
		$verb = $presenter->verb($entry->event);
		$actor = $entry->actorUid === null ? null : ($this->userManager->getDisplayName($entry->actorUid) ?? $entry->actorUid);
		$what = $actor === null ? $verb : $l->t('%1$s by %2$s', [$verb, $actor]);
		$when = $this->dateFormatter->formatDateTime($entry->time, 'long', 'short', $timeZone, $l);

		$plain = $name;
		$html = htmlspecialchars($name, ENT_QUOTES);
		// no link for a removal, nor when the address of the server is not known: then the name stands alone
		if ($entry->event !== WatchOptions::EVENT_DELETED && $entry->url !== null) {
			$html = '<a href="' . htmlspecialchars($entry->url, ENT_QUOTES) . '">' . $html . '</a>';
			$plain .= ' — ' . $entry->url;
		}

		$meta = $what . ' · ' . $when;
		$template->addBodyListItem($html, htmlspecialchars($meta, ENT_QUOTES), '', $plain, $meta);
	}
}
