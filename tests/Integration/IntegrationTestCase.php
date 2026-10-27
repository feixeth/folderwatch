<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Integration;

use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Db\WatchOptions;
use OCA\FolderWatch\Service\AccessChecker;
use OCA\FolderWatch\Service\AncestorResolver;
use OCA\FolderWatch\Service\EventBuffer;
use OCA\FolderWatch\Service\UserSettings;
use OCA\FolderWatch\Service\WatchMatcher;
use OCA\FolderWatch\Service\WatchService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * Real users, real shares, real file system events: only the sending is out of scope.
 */
abstract class IntegrationTestCase extends TestCase {
	/** @var list<string> */
	private array $uids = [];
	private string $prefix;
	/** @var array<string, mixed> config.php values to put back, key => former value (null = was absent) */
	private array $systemValues = [];

	protected function setUp(): void {
		$this->prefix = 'fwt' . substr(md5(uniqid('', true)), 0, 6);
		$this->get(EventBuffer::class)->reset();
	}

	protected function tearDown(): void {
		$this->restoreSystemValues();
		$this->get(EventBuffer::class)->reset();
		\OC_Util::tearDownFS();
		$this->get(IUserSession::class)->setUser(null);
		foreach ($this->uids as $uid) {
			$this->deleteRows($uid);
			$this->get(IUserManager::class)->get($uid)?->delete();
		}
		$this->uids = [];
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	protected function get(string $class): object {
		return Server::get($class);
	}

	/**
	 * Changes a config.php value for this test; it is put back afterwards. Tests run in the CLI, so
	 * this is also how they choose the address links are built from.
	 */
	protected function setSystemValue(string $key, ?string $value): void {
		$config = $this->get(IConfig::class);
		if (!array_key_exists($key, $this->systemValues)) {
			$this->systemValues[$key] = $config->getSystemValue($key, null);
		}
		if ($value === null) {
			$config->deleteSystemValue($key);
		} else {
			$config->setSystemValue($key, $value);
		}
	}

	private function restoreSystemValues(): void {
		$config = $this->get(IConfig::class);
		foreach ($this->systemValues as $key => $former) {
			if ($former === null) {
				$config->deleteSystemValue($key);
			} else {
				$config->setSystemValue($key, $former);
			}
		}
		$this->systemValues = [];
	}

	protected function createUser(string $name, ?string $displayName = null, bool $withEmail = true): string {
		$uid = $this->prefix . $name;
		$user = $this->get(IUserManager::class)->createUser($uid, 'Passw0rd-test-42');
		$this->assertInstanceOf(IUser::class, $user);
		if ($displayName !== null) {
			$user->setDisplayName($displayName);
		}
		if ($withEmail) {
			$user->setEMailAddress($this->emailOf($uid));
		}
		$this->uids[] = $uid;
		return $uid;
	}

	protected function emailOf(string $uid): string {
		return $uid . '@folderwatch.test';
	}

	/**
	 * Acts as this user from now on: the session user is the actor of the next events.
	 */
	protected function actAs(string $uid): Folder {
		$this->flush();
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($uid);
		$user = $this->get(IUserManager::class)->get($uid);
		$this->assertInstanceOf(IUser::class, $user);
		$this->get(IUserSession::class)->setUser($user);
		$this->resetCaches();
		return $this->get(IRootFolder::class)->getUserFolder($uid);
	}

	/** What the next request, or the next cron run, starts with: no mounts and no caches. */
	protected function newProcess(): void {
		$this->flush();
		\OC_Util::tearDownFS();
		$this->resetCaches();
	}

	/** Caches are meant to live for one request; a test plays several. */
	protected function resetCaches(): void {
		$this->get(AncestorResolver::class)->reset();
		$this->get(AccessChecker::class)->reset();
		$this->get(WatchMatcher::class)->reset();
		$this->get(UserSettings::class)->reset();
	}

	protected function flush(): void {
		$this->get(EventBuffer::class)->flush();
	}

	/**
	 * @param array<string, mixed> $options
	 */
	protected function watch(string $uid, string $path, array $options = []): int {
		return $this->get(WatchService::class)->add($uid, null, $path, $options)['id'];
	}

	protected function share(string $owner, string $path, string $recipient, int $permissions = 31): void {
		$node = $this->get(IRootFolder::class)->getUserFolder($owner)->get($path);
		$manager = $this->get(IShareManager::class);
		$share = $manager->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedBy($owner)
			->setSharedWith($recipient)
			->setPermissions($permissions);
		$manager->createShare($share);
	}

	protected function ignoreOwnActions(string $uid, bool $ignore): void {
		$this->get(IConfig::class)->setUserValue($uid, 'folderwatch', 'ignore_own', $ignore ? 'yes' : 'no');
	}

	/**
	 * Flushes, then lists the queue of the test users as "watcher:event:path" strings.
	 *
	 * @return list<string>
	 */
	protected function queue(): array {
		$this->flush();
		$names = array_flip(WatchOptions::EVENTS);
		$db = $this->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('user_id', 'event', 'path_hint')
			->from('folderwatch_queue')
			->orderBy('id');
		$rows = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			if (in_array($row['user_id'], $this->uids, true)) {
				$rows[] = substr($row['user_id'], strlen($this->prefix)) . ':' . $names[(int)$row['event']] . ':' . $row['path_hint'];
			}
		}
		$result->closeCursor();
		sort($rows);
		return $rows;
	}

	/**
	 * The links stored with the queued events, by path hint: [url, folder_url].
	 *
	 * @return array<string, array{?string, ?string}>
	 */
	protected function queueLinks(): array {
		$this->flush();
		$qb = $this->get(IDBConnection::class)->getQueryBuilder();
		$qb->select('user_id', 'path_hint', 'url', 'folder_url')->from('folderwatch_queue')->orderBy('id');
		$links = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			if (in_array($row['user_id'], $this->uids, true)) {
				$links[$row['path_hint']] = [$row['url'], $row['folder_url']];
			}
		}
		$result->closeCursor();
		return $links;
	}

	protected function clearQueue(): void {
		$this->flush();
		foreach ($this->uids as $uid) {
			$qb = $this->get(IDBConnection::class)->getQueryBuilder();
			$qb->delete('folderwatch_queue')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
		}
	}

	private function deleteRows(string $uid): void {
		$db = $this->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('notifications')->where($qb->expr()->eq('user', $qb->createNamedParameter($uid)))->executeStatement();
		foreach (['folderwatch_queue', 'folderwatch_watches', 'folderwatch_sends'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
		}
	}

	/**
	 * Notifications of folderwatch for a user, as the subject parameters stored by the sender.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function notificationsOf(string $uid): array {
		$qb = $this->get(IDBConnection::class)->getQueryBuilder();
		$qb->select('subject', 'subject_parameters', 'link')
			->from('notifications')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('app', $qb->createNamedParameter('folderwatch')))
			->orderBy('notification_id');
		$rows = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$rows[] = ['subject' => $row['subject'], 'link' => $row['link']] + json_decode($row['subject_parameters'], true, 512, JSON_THROW_ON_ERROR);
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * What the notification area shows for a user: the notifications of this app, run through the
	 * real Notifier like the Notifications app does. A notification that cannot be prepared (for
	 * instance because a rich object lacks a required key) is invisible to the user: it throws here.
	 *
	 * @return list<array{subject: string, message: string, richSubject: string, actions: list<string>, link: string, actionLinks: list<string>}>
	 */
	protected function renderedNotificationsOf(string $uid, string $lang = 'en'): array {
		$manager = $this->get(\OCP\Notification\IManager::class);
		$rendered = [];
		foreach ($this->notificationsOf($uid) as $row) {
			$params = $row;
			unset($params['link'], $params['subject']);
			$notification = $manager->createNotification();
			// as stored by the sender: no link, no action; the Notifier adds them when it is shown
			$notification->setApp('folderwatch')->setUser($uid)->setDateTime(new \DateTime())->setObject('watch', '1')->setSubject($row['subject'], $params);

			$prepared = $manager->prepare($notification, $lang);
			$rendered[] = [
				'subject' => $prepared->getParsedSubject(),
				'message' => $prepared->getParsedMessage(),
				'richSubject' => $prepared->getRichSubject(),
				'actions' => array_map(static fn ($a): string => $a->getParsedLabel(), $prepared->getParsedActions()),
				'link' => $prepared->getLink(),
				'actionLinks' => array_map(static fn ($a): string => $a->getLink(), $prepared->getParsedActions()),
			];
		}
		return $rendered;
	}

	/**
	 * Mails Mailpit received for an address, or null when no Mailpit is reachable.
	 *
	 * @return ?list<array{Subject: string, Text: string}>
	 */
	protected function mailsTo(string $address): ?array {
		$base = getenv('MAILPIT_URL') ?: 'http://mailpit:8025';
		$context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
		$list = @file_get_contents($base . '/api/v1/search?query=' . rawurlencode('to:' . $address), false, $context);
		if ($list === false) {
			return null;
		}
		$mails = [];
		foreach (json_decode($list, true)['messages'] ?? [] as $message) {
			$detail = json_decode((string)file_get_contents($base . '/api/v1/message/' . $message['ID'], false, $context), true);
			$mails[] = ['Subject' => $detail['Subject'], 'Text' => $detail['Text'], 'Html' => $detail['HTML']];
		}
		return $mails;
	}

	protected function sender(): \OCA\FolderWatch\Alert\AlertSender {
		return $this->get(\OCA\FolderWatch\Alert\AlertSender::class);
	}

	protected function ageQueue(int $seconds): void {
		$this->flush();
		$qb = $this->get(IDBConnection::class)->getQueryBuilder();
		$qb->update('folderwatch_queue')
			->set('created_at', $qb->func()->subtract('created_at', $qb->createNamedParameter($seconds)))
			->executeStatement();
	}

	protected function watchMapper(): WatchMapper {
		return $this->get(WatchMapper::class);
	}
}
