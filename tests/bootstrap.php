<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$loader = require __DIR__ . '/../vendor/autoload.php';

// nextcloud/ocp ships the public API without an autoload section.
$loader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP');
$loader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU');

// classes of server apps the code listens to
require_once __DIR__ . '/stubs/LoadAdditionalScriptsEvent.php';
