<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Controller;

use OCA\FolderWatch\ResponseDefinitions;
use OCA\FolderWatch\Service\WatchException;
use OCA\FolderWatch\Service\WatchService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 */
/**
 * @psalm-import-type FolderWatchWatch from ResponseDefinitions
 * @psalm-import-type FolderWatchWatchStatus from ResponseDefinitions
 */
class WatchController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WatchService $watchService,
		private readonly ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the folders watched by the current user
	 *
	 * @return DataResponse<Http::STATUS_OK, list<FolderWatchWatch>, array{}>
	 *
	 * 200: Watches listed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/watches')]
	public function index(): DataResponse {
		return new DataResponse($this->watchService->list($this->requireUser()));
	}

	/**
	 * Tell whether a folder is watched by the current user
	 *
	 * @param int $fileId File ID of the folder
	 * @return DataResponse<Http::STATUS_OK, FolderWatchWatchStatus, array{}>
	 *
	 * 200: Status returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/watches/status')]
	public function status(int $fileId): DataResponse {
		return new DataResponse($this->watchService->status($this->requireUser(), $fileId));
	}

	/**
	 * Watch a folder
	 *
	 * @param int|null $fileId File ID of the folder (or give a path)
	 * @param string|null $path Path of the folder in the user's files (or give a file ID)
	 * @param bool|null $recursive Also watch sub-folders (default: true)
	 * @param list<string>|null $events created, modified, deleted, moved_in (default: created, moved_in)
	 * @param list<string>|null $channels notification, mail (default: both)
	 * @param string|null $frequency immediate, hourly or daily (default: immediate)
	 * @param int|null $digestHour Hour of the daily digest, 0-23 (default: 8)
	 * @return DataResponse<Http::STATUS_OK, FolderWatchWatch, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT, array{message: string}, array{}>
	 *
	 * 200: Watch created
	 * 400: Invalid request
	 * 404: Folder not found
	 * 409: Already watched or limit reached
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/watches')]
	public function create(
		?int $fileId = null,
		?string $path = null,
		?bool $recursive = null,
		?array $events = null,
		?array $channels = null,
		?string $frequency = null,
		?int $digestHour = null,
	): DataResponse {
		try {
			$watch = $this->watchService->add(
				$this->requireUser(),
				$fileId,
				$path,
				$this->options($recursive, $events, $channels, $frequency, $digestHour),
			);
			return new DataResponse($watch);
		} catch (WatchException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}

	/**
	 * Change the settings of a watch
	 *
	 * @param int $id Watch ID
	 * @param bool|null $recursive Also watch sub-folders
	 * @param list<string>|null $events created, modified, deleted, moved_in
	 * @param list<string>|null $channels notification, mail
	 * @param string|null $frequency immediate, hourly or daily
	 * @param int|null $digestHour Hour of the daily digest, 0-23
	 * @return DataResponse<Http::STATUS_OK, FolderWatchWatch, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Watch updated
	 * 400: Invalid request
	 * 404: Watch not found
	 *
	 * @psalm-suppress InvalidReturnType, InvalidReturnStatement, ArgumentTypeCoercion WatchException is typed with every status the service uses, this route only meets 400 and 404
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/watches/{id}', requirements: ['id' => '\d+'])]
	public function update(
		int $id,
		?bool $recursive = null,
		?array $events = null,
		?array $channels = null,
		?string $frequency = null,
		?int $digestHour = null,
	): DataResponse {
		try {
			$watch = $this->watchService->update(
				$this->requireUser(),
				$id,
				$this->options($recursive, $events, $channels, $frequency, $digestHour),
			);
			return new DataResponse($watch);
		} catch (WatchException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}

	/**
	 * Stop watching a folder
	 *
	 * @param int $id Watch ID
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Watch removed
	 * 404: Watch not found
	 *
	 * @psalm-suppress InvalidReturnType, InvalidReturnStatement, ArgumentTypeCoercion see update()
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/watches/{id}', requirements: ['id' => '\d+'])]
	public function destroy(int $id): DataResponse {
		try {
			$this->watchService->delete($this->requireUser(), $id);
			return new DataResponse([]);
		} catch (WatchException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}

	/**
	 * @param list<string>|null $events
	 * @param list<string>|null $channels
	 * @return array<string, mixed>
	 */
	private function options(?bool $recursive, ?array $events, ?array $channels, ?string $frequency, ?int $digestHour): array {
		return array_filter([
			'recursive' => $recursive,
			'events' => $events,
			'channels' => $channels,
			'frequency' => $frequency,
			'digestHour' => $digestHour,
		], static fn (mixed $value): bool => $value !== null);
	}

	private function requireUser(): string {
		// NoAdminRequired still demands a logged-in user, so this is only a type guard.
		return $this->userId ?? throw new \LogicException('No user in an authenticated route');
	}
}
