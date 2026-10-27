<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Integration;

use OCA\FolderWatch\Service\AccessChecker;
use OCA\FolderWatch\Service\AncestorResolver;
use OCA\FolderWatch\Service\EventBuffer;
use OCP\Files\Folder;

/**
 * Who gets an alert, depending on who watches what and who acts, through the real file system.
 */
class MatchingTest extends IntegrationTestCase {
	private const ALL_EVENTS = ['created', 'modified', 'deleted', 'moved_in'];

	public function testPerRequestServicesAreSingletons(): void {
		foreach ([EventBuffer::class, AncestorResolver::class, AccessChecker::class] as $class) {
			$this->assertSame($this->get($class), $this->get($class), $class);
		}
	}

	public function testOwnerIsAlertedWhenAnotherUserAddsToTheirFolder(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$home = $this->actAs($alice);
		$home->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs');

		$this->actAs($bob)->get('Docs')->newFile('b.txt', 'hello');

		// NodeCreated and NodeWritten are one "created"
		$this->assertSame(['alice:created:b.txt'], $this->queue());
	}

	public function testRecipientWatchingTheSharedFolderIsAlerted(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($bob, '/Docs');

		$this->actAs($alice)->get('Docs')->newFile('a.txt', 'hello');

		$this->assertSame(['bob:created:a.txt'], $this->queue());
	}

	public function testOwnerWatchingAboveTheSharePointSeesTheRecipientsActions(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$home = $this->actAs($alice);
		$home->newFolder('Projects')->newFolder('Shared')->newFolder('Deep');
		$this->share($alice, 'Projects/Shared', $bob);
		$this->watch($alice, '/Projects');

		$bobHome = $this->actAs($bob);
		$bobHome->get('Shared')->newFile('top.txt', 'x');
		$bobHome->get('Shared/Deep')->newFile('deep.txt', 'x');

		$this->assertSame([
			'alice:created:Shared/Deep/deep.txt',
			'alice:created:Shared/top.txt',
		], $this->queue());
	}

	public function testBothSidesOfAShareCanWatchTheSameFolderAtOnce(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$carol = $this->createUser('carol');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->share($alice, 'Docs', $carol);
		$this->watch($alice, '/Docs');
		$this->watch($bob, '/Docs');
		$this->watch($carol, '/Docs');

		$this->actAs($carol)->get('Docs')->newFile('c.txt', 'x');

		$this->assertSame(['alice:created:c.txt', 'bob:created:c.txt'], $this->queue());
	}

	public function testRecursiveWatchSeesSubFoldersAndFlatWatchDoesNot(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs')->newFolder('Sub')->newFolder('Deeper');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs', ['recursive' => true]);
		$this->watch($bob, '/Docs', ['recursive' => false]);

		$this->actAs($bob)->get('Docs/Sub/Deeper')->newFile('x.txt', 'x');
		$this->assertSame(['alice:created:Sub/Deeper/x.txt'], $this->queue());

		$this->clearQueue();
		$this->actAs($alice)->get('Docs')->newFile('top.txt', 'x');
		$this->assertSame(['bob:created:top.txt'], $this->queue());
	}

	public function testOwnActionsAreIgnoredByDefaultAndReportedWhenAsked(): void {
		$alice = $this->createUser('alice');
		$home = $this->actAs($alice);
		$home->newFolder('Docs');
		$this->watch($alice, '/Docs');

		$home->get('Docs')->newFile('mine.txt', 'x');
		$this->assertSame([], $this->queue());

		$this->ignoreOwnActions($alice, false);
		$this->resetCaches();
		$this->actAs($alice)->get('Docs')->newFile('mine2.txt', 'x');
		$this->assertSame(['alice:created:mine2.txt'], $this->queue());
	}

	public function testIgnoredFileNamesRaiseNothing(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs');

		$docs = $this->actAs($bob)->get('Docs');
		foreach (['.DS_Store', 'Thumbs.db', '~$report.docx', '.~lock.report.docx#', 'upload.bin.part'] as $name) {
			$docs->newFile($name, 'x');
		}
		$docs->newFile('report.docx', 'x');

		$this->assertSame(['alice:created:report.docx'], $this->queue());
	}

	public function testModificationsAreReportedOnlyWhenSubscribed(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs', ['events' => ['modified']]);
		$this->watch($bob, '/Docs');
		$this->actAs($alice)->get('Docs')->newFile('a.txt', 'v1');
		$this->clearQueue();

		$file = $this->actAs($bob)->get('Docs/a.txt');
		$file->putContent('v2');
		$this->assertSame(['alice:modified:a.txt'], $this->queue());
	}

	public function testANewFileIsNotAModificationForAWatchThatOnlyWantsModifications(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs', ['events' => ['modified']]);

		$this->actAs($bob)->get('Docs')->newFile('new.txt', 'x');

		$this->assertSame([], $this->queue());
	}

	public function testDeletionsAreReportedOnlyWhenSubscribed(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs', ['events' => self::ALL_EVENTS]);
		$this->actAs($alice)->get('Docs')->newFile('a.txt', 'x');

		$this->actAs($bob)->get('Docs/a.txt')->delete();

		$this->assertSame(['alice:deleted:a.txt'], $this->queue());
	}

	public function testAFileCreatedAndDeletedInTheSameRequestLeavesNoTrace(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs', ['events' => self::ALL_EVENTS]);

		$docs = $this->actAs($bob)->get('Docs');
		$docs->newFile('tmp.txt', 'x')->delete();

		$this->assertSame([], $this->queue());
	}

	public function testMovesInAndOutOfAWatchedFolder(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$home = $this->actAs($alice);
		$home->newFolder('Docs')->newFolder('Sub');
		$home->newFolder('Elsewhere')->newFile('in.txt', 'x');
		$this->share($alice, 'Docs', $bob);
		$this->watch($bob, '/Docs', ['events' => self::ALL_EVENTS]);
		$this->clearQueue();

		// alice moves a file in from outside the watched tree
		$home = $this->actAs($alice);
		$home->get('Elsewhere/in.txt')->move($home->getPath() . '/Docs/in.txt');
		$this->assertSame(['bob:moved_in:in.txt'], $this->queue());
		$this->clearQueue();

		// moving inside the watched tree is not an incoming move, and a same-folder rename is ignored
		$home = $this->actAs($alice);
		$home->get('Docs/in.txt')->move($home->getPath() . '/Docs/Sub/in.txt');
		$home->get('Docs/Sub/in.txt')->move($home->getPath() . '/Docs/Sub/renamed.txt');
		$this->assertSame([], $this->queue());

		// leaving the watched tree is reported as a removal
		$home = $this->actAs($alice);
		$home->get('Docs/Sub/renamed.txt')->move($home->getPath() . '/Elsewhere/renamed.txt');
		$this->assertSame(['bob:deleted:Sub/renamed.txt'], $this->queue());
	}

	public function testWatchIsSilentOnceTheShareIsRemoved(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$carol = $this->createUser('carol');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->share($alice, 'Docs', $carol);
		$this->watch($bob, '/Docs');
		$this->watch($carol, '/Docs');

		$manager = $this->get(\OCP\Share\IManager::class);
		foreach ($manager->getSharesBy($alice, \OCP\Share\IShare::TYPE_USER) as $share) {
			if ($share->getSharedWith() === $bob) {
				$manager->deleteShare($share);
			}
		}

		$this->actAs($alice)->get('Docs')->newFile('a.txt', 'x');
		$this->assertSame(['carol:created:a.txt'], $this->queue());
	}

	public function testChangedPermissionsThatHideTheFolderSilenceTheWatch(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob, 1); // read only is still access
		$this->watch($bob, '/Docs');

		$this->actAs($alice)->get('Docs')->newFile('a.txt', 'x');
		$this->assertSame(['bob:created:a.txt'], $this->queue());
	}

	public function testFolderMovedToAnotherPlaceKeepsBeingWatchedByIdNotByPath(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$home = $this->actAs($alice);
		$home->newFolder('Docs');
		$home->newFolder('Archive');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs');
		$this->watch($bob, '/Docs');

		$home->get('Docs')->move($home->getPath() . '/Archive/Docs');
		$this->clearQueue();
		$this->resetCaches();

		// the watch of alice follows the folder: same file ID, new path
		$this->actAs($bob)->get('Docs')->newFile('b.txt', 'x');
		$this->assertSame(['alice:created:b.txt'], $this->queue());
	}

	public function testBigUploadIsQueuedAsOneBatch(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$this->actAs($alice)->newFolder('Docs');
		$this->share($alice, 'Docs', $bob);
		$this->watch($alice, '/Docs');

		$docs = $this->actAs($bob)->get('Docs');
		$this->assertInstanceOf(Folder::class, $docs);
		for ($i = 1; $i <= 200; $i++) {
			$docs->newFile("file-$i.txt", 'x');
		}

		$this->assertSame(200, $this->get(EventBuffer::class)->count());
		$this->assertCount(200, $this->queue());
	}
}
