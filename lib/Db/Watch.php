<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method bool|null getRecursive()
 * @method void setRecursive(bool $recursive)
 * @method int getEvents()
 * @method void setEvents(int $events)
 * @method int getChannels()
 * @method void setChannels(int $channels)
 * @method string getFrequency()
 * @method void setFrequency(string $frequency)
 * @method int getDigestHour()
 * @method void setDigestHour(int $digestHour)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int|null getLastSentAt()
 * @method void setLastSentAt(?int $lastSentAt)
 * @method int|null getInaccessibleSince()
 * @method void setInaccessibleSince(?int $inaccessibleSince)
 */
class Watch extends Entity {
	protected string $userId = '';
	protected int $fileId = 0;
	protected ?bool $recursive = true;
	protected int $events = WatchOptions::DEFAULT_EVENTS;
	protected int $channels = WatchOptions::CHANNEL_NOTIFICATION | WatchOptions::CHANNEL_MAIL;
	protected string $frequency = WatchOptions::FREQUENCY_IMMEDIATE;
	protected int $digestHour = 8;
	protected int $createdAt = 0;
	protected ?int $lastSentAt = null;
	protected ?int $inaccessibleSince = null;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('recursive', 'boolean');
		$this->addType('events', 'integer');
		$this->addType('channels', 'integer');
		$this->addType('digestHour', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('lastSentAt', 'integer');
		$this->addType('inaccessibleSince', 'integer');

		// A setter that is given the value the property already has does not mark the field as
		// changed, so a new watch would get the column default instead of that value. The columns
		// with a default are marked here; loading from the database resets the marks.
		foreach (['recursive', 'events', 'channels', 'frequency', 'digestHour'] as $field) {
			$this->markFieldUpdated($field);
		}
	}

	public function isRecursive(): bool {
		return $this->recursive ?? true;
	}
}
