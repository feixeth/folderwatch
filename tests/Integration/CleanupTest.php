<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Integration;

use OCA\FolderWatch\Db\QueueItem;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\SendLogMapper;
use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Service\CleanupService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * What happens to watches when their folder goes to the trash, is deleted for good, is unshared,
 * or when the account is deleted.
 */
class CleanupTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->get(IAppConfig::class)->setValueInt('folderwatch', 'cleanup_cursor', 0, true);
	}

	private function cleanup(): void {
		$this->newProcess();
		$this->get(CleanupService::class)->run();
	}

	private function since(int $watchId): ?int {
		return $this->watchMapper()->find($watchId)?->getInaccessibleSince();
	}

	private function makeUnreachableFor(int $watchId, int $seconds): void {
		$qb = $this->get(IDBConnection::class)->getQueryBuilder();
		$qb->update('folderwatch_watches')
			->set('inaccessible_since', $qb->createNamedParameter(time() - $seconds))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($watchId)))
			->executeStatement();
	}

	private function trash(): object {
		if (!$this->get(IAppManager::class)->isEnabledForAnyone('files_trashbin')) {
			$this->markTestSkipped('The trash bin is not enabled on this instance.');
		}
		return $this->get(\OCA\Files_Trashbin\Trash\ITrashManager::class);
	}

	/** Moves alice's Docs to the trash and returns the trash item. */
	private function trashDocs(string $alice): object {
		$trash = $this->trash();
		$this->actAs($alice)->get('Docs')->delete();
		$this->newProcess();
		$items = $trash->listTrashRoot($this->get(IUserManager::class)->get($alice));
		$this->assertCount(1, $items);
		return $items[0];
	}

	public function testAFolderInTheTrashSuspendsItsWatchAndRestoringRevivesIt(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');
		$id = $this->watch($alice, '/Docs');
		$item = $this->trashDocs($alice);

		$this->cleanup();
		$this->assertNotNull($this->since($id), 'the watch is suspended');
		$this->assertNotNull($this->watchMapper()->find($id), 'and kept');

		$this->actAs($alice); // restoring is done by the owner, with their files set up
		$this->trash()->restoreItem($item);
		$this->cleanup();
		$this->assertNull($this->since($id), 'the watch wakes up when the folder is back');
	}

	public function testNothingIsSentWhileTheFolderIsInTheTrash(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($bob, '/Docs');
		$this->actAs($alice)->get('Docs')->newFile('a.txt', 'x');
		$this->assertSame(['bob:created:a.txt'], $this->queue());

		$this->trashDocs($alice);
		$this->newProcess();
		$report = $this->sender()->process(true);

		$this->assertSame(0, $report->sent);
		$this->assertSame([], $this->notificationsOf($bob));
		$this->assertSame([], $this->queue());
	}

	public function testAFolderDeletedForGoodIsRemovedAfterTheGrace(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');
		$id = $this->watch($alice, '/Docs');
		$item = $this->trashDocs($alice);
		$this->trash()->removeItem($item);

		$this->cleanup();
		$this->assertNotNull($this->since($id), 'first it is only suspended');

		$this->makeUnreachableFor($id, CleanupService::DELETED_GRACE_SECONDS + 60);
		$this->cleanup();
		$this->assertNull($this->watchMapper()->find($id), 'then it is removed');
	}

	public function testAFolderInTheTrashIsNotRemovedJustBecauseItIsUnreachableForAWhile(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');
		$id = $this->watch($alice, '/Docs');
		$this->trashDocs($alice);
		$this->cleanup();

		$this->makeUnreachableFor($id, CleanupService::DELETED_GRACE_SECONDS + 60);
		$this->cleanup();
		$this->assertNotNull($this->watchMapper()->find($id), 'the file ID is still known: it can be restored');
	}

	public function testAWatchUnreachableForTooLongIsRemoved(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');
		$id = $this->watch($alice, '/Docs');
		$this->trashDocs($alice);
		$this->cleanup();

		$this->makeUnreachableFor($id, CleanupService::EXPIRY_SECONDS + 60);
		$this->cleanup();
		$this->assertNull($this->watchMapper()->find($id));
	}

	public function testAnUnsharedFolderSuspendsTheWatchOfTheRecipientAndResharingRevivesIt(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$aliceWatch = $this->watch($alice, '/Docs');
		$bobWatch = $this->watch($bob, '/Docs');

		$manager = $this->get(IShareManager::class);
		foreach ($manager->getSharesBy($alice, IShare::TYPE_USER) as $share) {
			$manager->deleteShare($share);
		}
		$this->cleanup();
		$this->assertNull($this->since($aliceWatch), 'the owner still has the folder');
		$this->assertNotNull($this->since($bobWatch), 'the recipient lost it');

		$this->share($alice, 'Docs', $bob);
		$this->cleanup();
		$this->assertNull($this->since($bobWatch));
	}

	public function testDeletingAnAccountRemovesItsWatchesAlertsAndSendLog(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$aliceWatch = $this->watch($alice, '/Docs');
		$bobWatch = $this->watch($bob, '/Docs');
		$this->actAs($alice)->get('Docs')->newFile('a.txt', 'x');
		$this->actAs($bob)->get('Docs')->newFile('b.txt', 'x');
		$this->flush();
		$this->get(SendLogMapper::class)->record($bob, time());
		$this->assertSame(['alice:created:b.txt', 'bob:created:a.txt'], $this->queue());

		$this->get(IUserManager::class)->get($bob)?->delete();

		$this->assertNull($this->watchMapper()->find($bobWatch));
		$this->assertNotNull($this->watchMapper()->find($aliceWatch), 'the watch of the other user stays');
		$this->assertSame(0, $this->get(SendLogMapper::class)->countSince($bob, 0));
		$this->assertSame(['alice:created:b.txt'], $this->queue(), 'only what was queued for bob is gone');
	}

	public function testAlertsWhoseWatchIsGoneAreSweptAway(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');
		$item = new QueueItem();
		$item->setWatchId(999_999_999);
		$item->setUserId($alice);
		$item->setFileId(1);
		$item->setEvent(WatchOptions::EVENT_CREATED);
		$item->setPathHint('lost.txt');
		$item->setCreatedAt(time());
		$this->get(QueueItemMapper::class)->insert($item);
		$this->assertSame(['alice:created:lost.txt'], $this->queue());

		$this->cleanup();
		$this->assertSame([], $this->queue());
	}

	public function testTheWatchOfAUserWhoseAccountVanishedIsRemoved(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');
		$id = $this->watch($alice, '/Docs');
		// the account disappears without the event being dispatched (e.g. a backend that dropped it)
		$qb = $this->get(IDBConnection::class)->getQueryBuilder();
		$qb->update('folderwatch_watches')->set('user_id', $qb->createNamedParameter($alice . '-gone'))->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();

		$this->cleanup();
		$this->assertNull($this->watchMapper()->find($id));
	}

	public function testTheCleanupGoesThroughEveryWatchOverSeveralRunsWhenTimeIsShort(): void {
		$alice = $this->createUser('alice');
		$this->actAs($alice)->newFolder('Docs');
		$id = $this->watch($alice, '/Docs');

		$this->newProcess();
		$service = $this->get(CleanupService::class);
		$service->run(0);
		$cursor = $this->get(IAppConfig::class)->getValueInt('folderwatch', 'cleanup_cursor', -1, true);
		$this->assertGreaterThanOrEqual(0, $cursor);
		$this->assertNotNull($this->watchMapper()->find($id));
	}
}
