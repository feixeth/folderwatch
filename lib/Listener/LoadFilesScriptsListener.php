<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Service\ScriptLoader;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Adds the "watch this folder" actions to the Files app, with the folders already watched
 * so the first render needs no request.
 *
 * @template-implements IEventListener<Event>
 */
class LoadFilesScriptsListener implements IEventListener {
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly WatchMapper $watchMapper,
		private readonly IInitialState $initialState,
		private readonly ScriptLoader $scripts,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		// the event is also dispatched on public share pages, where nobody is logged in
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$this->initialState->provideInitialState('watchedFileIds', array_map(
			static fn ($watch): int => $watch->getFileId(),
			$this->watchMapper->findByUser($user->getUID()),
		));
		$this->scripts->addInitScript('files-action');
	}
}
