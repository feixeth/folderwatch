<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The Files app is not part of nextcloud/ocp: stand-in for the unit tests, which run without a server.

namespace OCA\Files\Event;

use OCP\EventDispatcher\Event;

class LoadAdditionalScriptsEvent extends Event {
}
