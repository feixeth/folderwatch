<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Integration;

use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;

/**
 * Team folders have no owner and a storage of their own: watches must still match by file ID.
 */
class GroupFolderMatchingTest extends IntegrationTestCase {
	private ?int $folderId = null;
	private ?string $groupId = null;

	protected function setUp(): void {
		parent::setUp();
		if (!$this->get(IAppManager::class)->isEnabledForAnyone('groupfolders')) {
			$this->markTestSkipped('The groupfolders app is not enabled on this instance.');
		}
	}

	protected function tearDown(): void {
		parent::tearDown();
		if ($this->folderId !== null) {
			$this->get(\OCA\GroupFolders\Folder\FolderManager::class)->removeFolder($this->folderId);
		}
		if ($this->groupId !== null) {
			$this->get(IGroupManager::class)->get($this->groupId)?->delete();
		}
	}

	private function createTeamFolder(string ...$members): string {
		$name = 'Team' . substr(md5(uniqid('', true)), 0, 6);
		$group = $this->get(IGroupManager::class)->createGroup($name);
		$this->groupId = $name;
		foreach ($members as $uid) {
			$group->addUser($this->get(\OCP\IUserManager::class)->get($uid));
		}
		$manager = $this->get(\OCA\GroupFolders\Folder\FolderManager::class);
		$this->folderId = $manager->createFolder($name);
		$manager->addApplicableGroup($this->folderId, $name);
		return $name;
	}

	public function testMembersAreAlertedAboutEachOthersChanges(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$carol = $this->createUser('carol');
		$team = $this->createTeamFolder($alice, $bob, $carol);

		$this->actAs($alice)->newFolder($team . '/Reports');
		$this->watch($alice, '/' . $team . '/Reports', ['recursive' => false]);
		$this->watch($bob, '/' . $team);
		$this->watch($carol, '/' . $team, ['recursive' => false]);

		$this->actAs($carol)->get($team . '/Reports')->newFile('q3.pdf', 'x');

		// bob watches the root of the team folder recursively, carol is the actor of her own flat watch
		$this->assertSame([
			'alice:created:q3.pdf',
			'bob:created:Reports/q3.pdf',
		], $this->queue());
	}

	public function testWatchOnTheRootOfATeamFolderIgnoresUsersOutsideTheGroup(): void {
		$alice = $this->createUser('alice');
		$bob = $this->createUser('bob');
		$team = $this->createTeamFolder($alice);

		$this->actAs($alice)->get($team);
		$this->watch($alice, '/' . $team);
		$this->actAs($bob);
		$this->assertFalse($this->get(IRootFolder::class)->getUserFolder($bob)->nodeExists($team));
		$this->assertSame([], $this->queue());
	}
}
