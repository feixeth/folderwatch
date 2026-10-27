<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Controller;

use OCA\FolderWatch\ResponseDefinitions;
use OCA\FolderWatch\Service\UserSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type FolderWatchUserSettings from ResponseDefinitions
 */
class UserSettingsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly UserSettings $settings,
		private readonly ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get the personal settings of the current user
	 *
	 * @return DataResponse<Http::STATUS_OK, FolderWatchUserSettings, array{}>
	 *
	 * 200: Settings returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/settings')]
	public function index(): DataResponse {
		return new DataResponse(['ignoreOwnActions' => $this->settings->ignoreOwnActions($this->requireUser())]);
	}

	/**
	 * Change the personal settings of the current user
	 *
	 * @param bool|null $ignoreOwnActions Do not alert me about what I do myself
	 * @return DataResponse<Http::STATUS_OK, FolderWatchUserSettings, array{}>
	 *
	 * 200: Settings saved
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/settings')]
	public function update(?bool $ignoreOwnActions = null): DataResponse {
		$userId = $this->requireUser();
		if ($ignoreOwnActions !== null) {
			$this->settings->setIgnoreOwnActions($userId, $ignoreOwnActions);
		}
		return new DataResponse(['ignoreOwnActions' => $this->settings->ignoreOwnActions($userId)]);
	}

	private function requireUser(): string {
		return $this->userId ?? throw new \LogicException('No user in an authenticated route');
	}
}
