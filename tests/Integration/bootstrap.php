<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Loads a real Nextcloud (the dev container) so the tests exercise the real file system,
// sharing and database layers. OCP comes from the server, not from vendor/.
define('PHPUNIT_RUN', 1);
$serverRoot = getenv('NC_SERVER_ROOT') ?: '/var/www/html';
require_once $serverRoot . '/lib/base.php';

require_once __DIR__ . '/../../vendor/autoload.php';

\OC::$CLI = true;
\OC_App::loadApps();
if (!\OC::$server->get(\OCP\App\IAppManager::class)->isEnabledForAnyone('folderwatch')) {
	throw new RuntimeException('The folderwatch app must be enabled on the instance running the tests.');
}
