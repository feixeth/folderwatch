<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Integration;

use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Service\WatchService;

/**
 * What the database really holds after the service created a watch.
 */
class WatchServiceTest extends IntegrationTestCase {
	public function testANewWatchGetsTheDocumentedDefaults(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');

		$id = $this->watch($alice, '/Docs');

		$watch = $this->watchMapper()->find($id);
		$this->assertNotNull($watch);
		$this->assertSame(WatchOptions::EVENT_CREATED | WatchOptions::EVENT_MOVED_IN, $watch->getEvents());
		$this->assertSame(WatchOptions::CHANNEL_NOTIFICATION | WatchOptions::CHANNEL_MAIL, $watch->getChannels());
		$this->assertSame('immediate', $watch->getFrequency());
		$this->assertSame(8, $watch->getDigestHour());
		$this->assertTrue($watch->isRecursive());
		$this->assertNull($watch->getLastSentAt());
		$this->assertNull($watch->getInaccessibleSince());
		$this->assertGreaterThan(0, $watch->getCreatedAt());
	}

	public function testChosenOptionsAreStoredEvenWhenTheyEqualADefault(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');

		$id = $this->watch($alice, '/Docs', [
			'recursive' => true,
			'events' => ['created'],
			'channels' => ['notification', 'mail'],
			'frequency' => 'immediate',
			'digestHour' => 8,
		]);

		$watch = $this->watchMapper()->find($id);
		$this->assertSame(WatchOptions::EVENT_CREATED, $watch?->getEvents());
	}

	public function testUpdatesReachTheDatabase(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');
		$id = $this->watch($alice, '/Docs');

		$this->get(WatchService::class)->update($alice, $id, ['recursive' => false, 'frequency' => 'daily', 'digestHour' => 17, 'events' => ['modified', 'deleted']]);

		$watch = $this->watchMapper()->find($id);
		$this->assertFalse($watch?->isRecursive());
		$this->assertSame('daily', $watch?->getFrequency());
		$this->assertSame(17, $watch?->getDigestHour());
		$this->assertSame(WatchOptions::EVENT_MODIFIED | WatchOptions::EVENT_DELETED, $watch?->getEvents());
	}

	public function testALoadedWatchIsNotConsideredChanged(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');
		$id = $this->watch($alice, '/Docs');

		$this->assertSame([], $this->watchMapper()->find($id)?->getUpdatedFields());
	}
}
