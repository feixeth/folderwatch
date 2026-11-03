<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Installs an app from its release archive the way the App Store does, inside a clean Nextcloud:
 * OC\Installer::downloadApp() runs for real, with the certificate chain check, the revocation list, the common
 * name, the download over HTTP, the signature of the release, the extraction of the archive and the check that it
 * holds exactly one folder. Then installApp() and enableApp(), as the apps page does. Only the list of apps that
 * the App Store would answer is replaced, by an entry for this archive.
 *
 *   php install-from-archive.php <app id> <developer certificate> <release signature file> <download url>
 *
 * Run by tools/test-install.sh, as the web server user, from the root of Nextcloud.
 */

use OC\App\AppManager;
use OC\App\AppStore\Fetcher\AppFetcher;
use OC\Installer;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

if (PHP_SAPI !== 'cli' || $argc !== 5) {
	fwrite(STDERR, "usage: php install-from-archive.php <app id> <certificate> <signature file> <download url>\n");
	exit(2);
}
[, $appId, $certificatePath, $signaturePath, $downloadUrl] = $argv;

require_once '/var/www/html/lib/base.php';

$release = [
	'id' => $appId,
	'certificate' => trim((string)file_get_contents($certificatePath)),
	'releases' => [[
		'download' => $downloadUrl,
		'signature' => preg_replace('/\s+/', '', (string)file_get_contents($signaturePath)),
		'version' => '0',
	]],
];

// the answer of the App Store, for this one app
$fetcher = new class($release) extends AppFetcher {
	/** @param array<string, mixed> $release */
	public function __construct(
		private array $release,
	) {
	}

	public function get($allowUnstable = false): array {
		return [$this->release];
	}
};

$installer = new Installer(
	$fetcher,
	\OC::$server->get(\OCP\Http\Client\IClientService::class),
	\OC::$server->get(ITempManager::class),
	\OC::$server->get(LoggerInterface::class),
	\OC::$server->get(IConfig::class),
	\OC::$server->get(AppManager::class),
	\OC::$server->get(IFactory::class),
	true,
);
$appManager = \OC::$server->get(AppManager::class);

try {
	$installer->downloadApp($appId);
	echo "downloaded and extracted\n";
	$installer->installApp($appId);
	$appManager->enableApp($appId);
	echo "installed and enabled: $appId " . $appManager->getAppVersion($appId, false) . "\n";
} catch (\Throwable $e) {
	echo 'INSTALL FAILED: ' . $e->getMessage() . "\n";
	exit(1);
}
