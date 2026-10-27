<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Controller;

use InvalidArgumentException;
use OCA\FolderWatch\ResponseDefinitions;
use OCA\FolderWatch\Service\AppSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Administrators only: there is deliberately no NoAdminRequired here.
 *
 */
/**
 * @psalm-import-type FolderWatchAdminSettings from ResponseDefinitions
 */
class AdminSettingsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AppSettings $settings,
		private readonly IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get the instance-wide settings
	 *
	 * @return DataResponse<Http::STATUS_OK, FolderWatchAdminSettings, array{}>
	 *
	 * 200: Settings returned
	 */
	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/settings')]
	public function index(): DataResponse {
		return new DataResponse($this->settings->toArray());
	}

	/**
	 * Change the instance-wide settings
	 *
	 * @param bool|null $mailEnabled Allow the e-mail channel on this instance
	 * @param int|null $maxWatchesPerUser Folders one user may watch (1-1000)
	 * @param int|null $maxSendsPerHour Alerts one user may receive per hour, 0 for no limit (0-1000)
	 * @param list<string>|null $ignoredPatterns File name patterns that never raise an alert
	 * @return DataResponse<Http::STATUS_OK, FolderWatchAdminSettings, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 *
	 * 200: Settings saved
	 * 400: A value is invalid, nothing was saved
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/admin/settings')]
	public function update(
		?bool $mailEnabled = null,
		?int $maxWatchesPerUser = null,
		?int $maxSendsPerHour = null,
		?array $ignoredPatterns = null,
	): DataResponse {
		try {
			$this->settings->update(array_filter([
				'mailEnabled' => $mailEnabled,
				'maxWatchesPerUser' => $maxWatchesPerUser,
				'maxSendsPerHour' => $maxSendsPerHour,
				'ignoredPatterns' => $ignoredPatterns,
			], static fn (mixed $value): bool => $value !== null));
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['message' => $this->l->t('Invalid value for "%s".', [$e->getMessage()])], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->settings->toArray());
	}
}
