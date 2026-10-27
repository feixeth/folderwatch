<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->ignoreVCS(true)
	->notPath('/^tests\/stubs\//')
	->exclude(['js', 'node_modules', 'vendor', 'build'])
	->in(__DIR__);
return $config;
