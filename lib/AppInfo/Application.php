<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Listener\LoadFilesScriptsListener;
use OCA\FolderWatch\Listener\NodeEventsListener;
use OCA\FolderWatch\Listener\UserDeletedListener;
use OCA\FolderWatch\Notification\Notifier;
use OCA\FolderWatch\Service\AccessChecker;
use OCA\FolderWatch\Service\AncestorResolver;
use OCA\FolderWatch\Service\EventBuffer;
use OCA\FolderWatch\Service\UserSettings;
use OCA\FolderWatch\SetupCheck\BackgroundJobsCheck;
use OCA\FolderWatch\SetupCheck\CliUrlCheck;
use OCA\FolderWatch\SetupCheck\MailServerCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\User\Events\UserDeletedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'folderwatch';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// These keep per-request state (caches, pending alerts): there must be one instance.
		$context->registerService(EventBuffer::class, static fn (ContainerInterface $c): EventBuffer => new EventBuffer(
			$c->get(QueueItemMapper::class),
			$c->get(ITimeFactory::class),
			$c->get(LoggerInterface::class),
		), true);
		$context->registerService(AncestorResolver::class, static fn (ContainerInterface $c): AncestorResolver => new AncestorResolver(
			$c->get(IRootFolder::class),
			$c->get(LoggerInterface::class),
		), true);
		$context->registerService(AccessChecker::class, static fn (ContainerInterface $c): AccessChecker => new AccessChecker(
			$c->get(IRootFolder::class),
			$c->get(LoggerInterface::class),
		), true);
		$context->registerService(UserSettings::class, static fn (ContainerInterface $c): UserSettings => new UserSettings(
			$c->get(IConfig::class),
		), true);

		$context->registerNotifierService(Notifier::class);
		$context->registerSetupCheck(BackgroundJobsCheck::class);
		$context->registerSetupCheck(CliUrlCheck::class);
		$context->registerSetupCheck(MailServerCheck::class);

		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptsListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(NodeCreatedEvent::class, NodeEventsListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, NodeEventsListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, NodeEventsListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, NodeEventsListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
