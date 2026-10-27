<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Listener\NodeEventsListener;
use OCA\FolderWatch\Service\AncestorResolver;
use OCA\FolderWatch\Service\AppSettings;
use OCA\FolderWatch\Service\EventBuffer;
use OCA\FolderWatch\Service\LinkResolver;
use OCA\FolderWatch\Service\NodeLocation;
use OCA\FolderWatch\Service\PathFilter;
use OCA\FolderWatch\Service\WatchMatcher;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NodeEventsListenerTest extends TestCase {
	private AncestorResolver&MockObject $resolver;
	private WatchMatcher&MockObject $matcher;
	private LinkResolver&MockObject $links;
	private EventBuffer&MockObject $buffer;
	private NodeEventsListener $listener;
	private PathFilter $pathFilter;
	private IUserSession&MockObject $session;
	/** @var array<int, bool> */
	private array $created = [];

	protected function setUp(): void {
		$this->resolver = $this->createMock(AncestorResolver::class);
		$this->matcher = $this->createMock(WatchMatcher::class);
		$this->links = $this->createMock(LinkResolver::class);
		$this->links->method('fileUrl')->willReturnCallback(static fn (int $id): string => "https://nc.test/f/$id");
		$this->buffer = $this->createMock(EventBuffer::class);
		$this->buffer->method('markCreated')->willReturnCallback(function (int $id): void {
			$this->created[$id] = true;
		});
		$this->buffer->method('wasCreated')->willReturnCallback(fn (int $id): bool => isset($this->created[$id]));

		$settings = $this->createMock(AppSettings::class);
		$settings->method('getIgnoredPatterns')->willReturn(AppSettings::DEFAULT_PATTERNS);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
		$this->pathFilter = new PathFilter($settings);

		$this->listener = new NodeEventsListener(
			$this->resolver,
			$this->matcher,
			$this->links,
			$this->buffer,
			$this->pathFilter,
			$this->session,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function node(string $class, string $path, int $id = 500): Node&MockObject {
		$node = $this->createMock($class);
		$node->method('getPath')->willReturn($path);
		$node->method('getName')->willReturn(basename($path));
		$node->method('getId')->willReturn($id);
		return $node;
	}

	private function location(int $parentId): NodeLocation {
		return new NodeLocation($parentId, [$parentId], [$parentId => 'files/x']);
	}

	public function testNewFileRaisesOneCreatedEventEvenThoughItIsWrittenToo(): void {
		$file = $this->node(File::class, '/alice/files/Docs/a.txt');
		$this->resolver->method('locate')->willReturn($this->location(20));
		$watch = WatchFactory::make(1, 'alice', 20);
		$this->matcher->expects($this->once())->method('match')
			->with($this->anything(), WatchOptions::EVENT_CREATED, 'bob', null)->willReturn([$watch]);
		$this->buffer->expects($this->once())->method('add')
			->with($watch, 500, WatchOptions::EVENT_CREATED, 'bob', 'a.txt', 'https://nc.test/f/500', 'https://nc.test/f/20');

		$this->listener->handle(new NodeCreatedEvent($file));
		$this->listener->handle(new NodeWrittenEvent($file));
	}

	public function testTheLinksAreWorkedOutNowAndKeptWithTheEvent(): void {
		$file = $this->node(File::class, '/alice/files/Docs/a.txt', 500);
		$this->resolver->method('locate')->willReturn($this->location(20));
		$one = WatchFactory::make(1, 'alice', 20);
		$two = WatchFactory::make(2, 'carol', 10);
		$this->matcher->method('match')->willReturn([$one, $two]);
		$calls = [];
		$this->buffer->method('add')->willReturnCallback(function ($watch, $fileId, $event, $actor, $hint, $url, $folderUrl) use (&$calls): void {
			$calls[] = [$watch->getId(), $url, $folderUrl];
		});

		$this->listener->handle(new NodeCreatedEvent($file));

		// the file link is the same for everybody; each watch gets the link of its own folder
		$this->assertSame([[1, 'https://nc.test/f/500', 'https://nc.test/f/20'], [2, 'https://nc.test/f/500', 'https://nc.test/f/10']], $calls);
	}

	public function testALinkThatCannotBeBuiltIsStoredAsNothing(): void {
		$links = $this->createMock(LinkResolver::class);
		$links->method('fileUrl')->willReturn(null);
		$listener = new NodeEventsListener($this->resolver, $this->matcher, $links, $this->buffer, $this->pathFilter, $this->session, $this->createMock(LoggerInterface::class));
		$this->resolver->method('locate')->willReturn($this->location(20));
		$watch = WatchFactory::make(1, 'alice', 20);
		$this->matcher->method('match')->willReturn([$watch]);
		$this->buffer->expects($this->once())->method('add')->with($watch, 500, WatchOptions::EVENT_CREATED, 'bob', 'a.txt', null, null);

		$listener->handle(new NodeCreatedEvent($this->node(File::class, '/alice/files/Docs/a.txt')));
	}

	public function testOverwriteRaisesModified(): void {
		$file = $this->node(File::class, '/alice/files/Docs/a.txt');
		$this->resolver->method('locate')->willReturn($this->location(20));
		$this->matcher->expects($this->once())->method('match')
			->with($this->anything(), WatchOptions::EVENT_MODIFIED, 'bob', null)->willReturn([]);

		$this->listener->handle(new NodeWrittenEvent($file));
	}

	public function testDeleteRaisesDeleted(): void {
		$file = $this->node(File::class, '/alice/files/Docs/a.txt');
		$this->resolver->method('locate')->willReturn($this->location(20));
		$this->matcher->expects($this->once())->method('match')
			->with($this->anything(), WatchOptions::EVENT_DELETED, 'bob', null)->willReturn([]);

		$this->listener->handle(new NodeDeletedEvent($file));
	}

	public function testIgnoredNamesAndInternalPathsRaiseNothing(): void {
		$this->resolver->expects($this->never())->method('locate');
		$this->matcher->expects($this->never())->method('match');

		foreach ([
			'/alice/files/Docs/.DS_Store',
			'/alice/files/Docs/upload.part',
			'/alice/files_versions/Docs/a.txt.v1',
			'/alice/files_trashbin/files/a.txt.d1',
			'/alice/uploads/web-file-upload/00001',
			'/appdata_x/preview/1.png',
		] as $path) {
			$this->listener->handle(new NodeCreatedEvent($this->node(File::class, $path)));
			$this->listener->handle(new NodeWrittenEvent($this->node(File::class, $path)));
			$this->listener->handle(new NodeDeletedEvent($this->node(File::class, $path)));
		}
	}

	public function testOtherEventsAreIgnored(): void {
		$this->matcher->expects($this->never())->method('match');
		$this->listener->handle(new NodeTouchedEvent($this->node(File::class, '/alice/files/Docs/a.txt')));
		$this->listener->handle(new class() extends Event {
		});
	}

	public function testIncomingMoveIsReportedAgainstTheTargetFolder(): void {
		$source = $this->node(File::class, '/alice/files/hello.txt', 0);
		$target = $this->node(File::class, '/alice/files/Docs/hello.txt', 155);
		$from = $this->location(10);
		$to = $this->location(20);
		$this->resolver->method('locate')->willReturnMap([[$source, $from], [$target, $to]]);
		$watch = WatchFactory::make(1, 'carol', 20);
		$this->matcher->method('match')->willReturnCallback(
			static fn (NodeLocation $at, int $event) => $at === $to && $event === WatchOptions::EVENT_MOVED_IN ? [$watch] : [],
		);
		$this->matcher->expects($this->exactly(2))->method('match');
		$this->buffer->expects($this->once())->method('add')
			->with($watch, 155, WatchOptions::EVENT_MOVED_IN, 'bob', 'hello.txt', 'https://nc.test/f/155', 'https://nc.test/f/20');

		$this->listener->handle(new NodeRenamedEvent($source, $target));
	}

	public function testMoveOutIsReportedAsDeletedFromTheSourceFolder(): void {
		$source = $this->node(File::class, '/alice/files/Docs/a.txt', 0);
		$target = $this->node(File::class, '/alice/files/Elsewhere/a.txt', 230);
		$from = $this->location(20);
		$to = $this->location(40);
		$this->resolver->method('locate')->willReturnMap([[$source, $from], [$target, $to]]);
		$watch = WatchFactory::make(1, 'carol', 20);
		$this->matcher->method('match')->willReturnCallback(
			static fn (NodeLocation $at, int $event) => $at === $from && $event === WatchOptions::EVENT_DELETED ? [$watch] : [],
		);
		$this->buffer->expects($this->once())->method('add')
			->with($watch, 230, WatchOptions::EVENT_DELETED, 'bob', 'a.txt', null, 'https://nc.test/f/20');

		$this->listener->handle(new NodeRenamedEvent($source, $target));
	}

	public function testMoveBetweenTwoWatchedPartsPassesTheOtherLocationToTheMatcher(): void {
		$source = $this->node(File::class, '/alice/files/Docs/a.txt', 0);
		$target = $this->node(File::class, '/alice/files/Docs/Sub/a.txt', 230);
		$from = $this->location(20);
		$to = $this->location(30);
		$this->resolver->method('locate')->willReturnMap([[$source, $from], [$target, $to]]);
		$calls = [];
		$this->matcher->method('match')->willReturnCallback(function (NodeLocation $at, int $event, ?string $actor, ?NodeLocation $exclude) use (&$calls, $from, $to) {
			$calls[] = [$at, $event, $exclude];
			return [];
		});

		$this->listener->handle(new NodeRenamedEvent($source, $target));

		$this->assertSame([[$to, WatchOptions::EVENT_MOVED_IN, $from], [$from, WatchOptions::EVENT_DELETED, $to]], $calls);
	}

	public function testRenameInTheSameFolderIsIgnored(): void {
		$source = $this->node(File::class, '/alice/files/Docs/a.txt', 0);
		$target = $this->node(File::class, '/alice/files/Docs/b.txt', 230);
		$this->resolver->method('locate')->willReturn($this->location(20));
		$this->matcher->expects($this->never())->method('match');

		$this->listener->handle(new NodeRenamedEvent($source, $target));
	}

	public function testMovingAFolderForgetsCachedLocations(): void {
		$source = $this->node(Folder::class, '/alice/files/A', 0);
		$target = $this->node(Folder::class, '/alice/files/B/A', 90);
		$this->resolver->method('locate')->willReturn(null);
		$this->resolver->expects($this->once())->method('reset');
		$this->matcher->expects($this->once())->method('reset');

		$this->listener->handle(new NodeRenamedEvent($source, $target));
	}

	public function testAFailureNeverReachesTheFileOperation(): void {
		$file = $this->node(File::class, '/alice/files/Docs/a.txt');
		$this->resolver->method('locate')->willThrowException(new \RuntimeException('boom'));

		$this->listener->handle(new NodeCreatedEvent($file));
		$this->addToAssertionCount(1);
	}
}
