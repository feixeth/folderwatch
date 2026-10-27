<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Service;

use OCA\FolderWatch\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Absolute links to files and folders, of the form /index.php/f/<file id>: the file id is the same for every
 * user, so the link opens the file wherever the reader has the folder (renamed, in a sub-folder, in a team folder).
 *
 * The address comes from:
 *  - the request, in a web request, but only when its host can be trusted: the host of overwrite.cli.url
 *    when that is usable, otherwise the first of trusted_domains. A request that came in through any other
 *    name (an internal address, a former domain, a secondary one) gets its link rebuilt on overwrite.cli.url;
 *  - overwrite.cli.url elsewhere (occ, cron, scan).
 * With no trustworthy address at all there is no link: null is returned and a warning is logged, at most once a day.
 */
class LinkResolver {
	private const WARNING_KEY = 'link_warning_at';
	private const WARNING_INTERVAL = 86400;

	/** @var array<int, ?string> */
	private array $links = [];

	public function __construct(
		private readonly RequestContext $context,
		private readonly IURLGenerator $urlGenerator,
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	public function fileUrl(int $fileId): ?string {
		if (!array_key_exists($fileId, $this->links)) {
			$this->links[$fileId] = $this->context->isWebRequest() ? $this->webUrl($fileId) : $this->cliUrl($fileId);
		}
		return $this->links[$fileId];
	}

	public function reset(): void {
		$this->links = [];
	}

	private function webUrl(int $fileId): ?string {
		$base = $this->configuredBase();
		$requested = $this->requestedUrl($fileId);
		if ($base !== null) {
			return CliUrl::sameHost($requested, $base) ? $requested : $base . '/index.php/f/' . $fileId;
		}

		$trusted = $this->firstTrustedDomain();
		if ($trusted !== null && CliUrl::sameHost($requested, $trusted)) {
			return $requested;
		}
		$this->warnOncePerDay();
		return null;
	}

	private function cliUrl(int $fileId): ?string {
		$base = $this->configuredBase();
		if ($base === null) {
			$this->warnOncePerDay();
			return null;
		}
		return $base . '/index.php/f/' . $fileId;
	}

	private function configuredBase(): ?string {
		return CliUrl::normalize($this->config->getSystemValueString('overwrite.cli.url', ''));
	}

	/** The first trusted domain, unless it is not a real address (empty, or the machine itself). */
	private function firstTrustedDomain(): ?string {
		$domains = $this->config->getSystemValue('trusted_domains', []);
		$first = is_array($domains) ? reset($domains) : false;
		if (!is_string($first) || trim($first) === '') {
			return null;
		}
		$host = parse_url('//' . trim($first), PHP_URL_HOST);
		return is_string($host) && !CliUrl::isLocalHost($host) ? trim($first) : null;
	}

	private function requestedUrl(int $fileId): string {
		try {
			return $this->urlGenerator->linkToRouteAbsolute('files.view.showFile', ['fileid' => $fileId]);
		} catch (\Throwable) {
			return $this->urlGenerator->getAbsoluteURL('/f/' . $fileId);
		}
	}

	private function warnOncePerDay(): void {
		$now = $this->timeFactory->getTime();
		if ($now - $this->appConfig->getValueInt(Application::APP_ID, self::WARNING_KEY, 0, true) < self::WARNING_INTERVAL) {
			return;
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::WARNING_KEY, $now, true);
		$this->logger->warning(
			'Folder Watch: no trustworthy address for links (overwrite.cli.url is empty or points to localhost, and the request did not come in through the first trusted domain), so alerts carry no link. Set overwrite.cli.url in config.php to the public address of this Nextcloud.',
			['app' => Application::APP_ID],
		);
	}
}
