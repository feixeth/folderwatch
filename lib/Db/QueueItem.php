<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getWatchId()
 * @method void setWatchId(int $watchId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getEvent()
 * @method void setEvent(int $event)
 * @method string|null getActorUid()
 * @method void setActorUid(?string $actorUid)
 * @method string|null getPathHint()
 * @method void setPathHint(?string $pathHint)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method string|null getFolderUrl()
 * @method void setFolderUrl(?string $folderUrl)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class QueueItem extends Entity {
	protected int $watchId = 0;
	protected string $userId = '';
	protected int $fileId = 0;
	protected int $event = WatchOptions::EVENT_CREATED;
	protected ?string $actorUid = null;
	protected ?string $pathHint = null;
	protected ?string $url = null;
	protected ?string $folderUrl = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('watchId', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('event', 'integer');
		$this->addType('createdAt', 'integer');

		// See Watch: a value equal to the property default would otherwise be left out of the INSERT.
		foreach (['watchId', 'userId', 'fileId', 'event', 'createdAt'] as $field) {
			$this->markFieldUpdated($field);
		}
	}
}
