<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch;

/**
 * Shapes of what the API returns, shared by the controllers, the services and the OpenAPI spec.
 *
 * @psalm-type FolderWatchWatch = array{
 *     id: int,
 *     fileId: int,
 *     path: ?string,
 *     name: ?string,
 *     url: string,
 *     accessible: bool,
 *     recursive: bool,
 *     events: list<string>,
 *     channels: list<string>,
 *     frequency: string,
 *     digestHour: int,
 *     createdAt: int,
 *     lastSentAt: ?int,
 * }
 *
 * @psalm-type FolderWatchWatchStatus = array{
 *     watched: bool,
 *     id: ?int,
 * }
 *
 * @psalm-type FolderWatchAdminSettings = array{
 *     mailEnabled: bool,
 *     maxWatchesPerUser: int,
 *     maxSendsPerHour: int,
 *     ignoredPatterns: list<string>,
 * }
 *
 * @psalm-type FolderWatchUserSettings = array{
 *     ignoreOwnActions: bool,
 * }
 */
final class ResponseDefinitions {
}
