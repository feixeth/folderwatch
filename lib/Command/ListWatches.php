<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Command;

use OCA\FolderWatch\Db\QueueItemMapper;
use OCA\FolderWatch\Db\WatchMapper;
use OCA\FolderWatch\Service\WatchService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListWatches extends Command {
	public function __construct(
		private readonly WatchMapper $watchMapper,
		private readonly QueueItemMapper $queueItemMapper,
		private readonly WatchService $watchService,
		private readonly IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('folderwatch:list')
			->setDescription('List the watched folders')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only the watches of this user')
			->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output format: table (default) or json', 'table');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$format = (string)$input->getOption('output');
		if (!in_array($format, ['table', 'json'], true)) {
			$output->writeln('<error>--output must be "table" or "json"</error>');
			return self::INVALID;
		}

		$uid = $input->getOption('user');
		if ($uid !== null && !$this->userManager->userExists((string)$uid)) {
			$output->writeln('<error>User "' . $uid . '" does not exist</error>');
			return self::FAILURE;
		}

		$watches = $uid === null ? $this->watchMapper->findAll() : $this->watchMapper->findByUser((string)$uid);
		$pending = $this->queueItemMapper->getPendingStats();

		$rows = [];
		foreach ($watches as $watch) {
			$described = $this->watchService->describe($watch);
			$rows[] = [
				'id' => $watch->getId(),
				'user' => $watch->getUserId(),
				'fileId' => $watch->getFileId(),
				'path' => $described['path'],
				'status' => $watch->getInaccessibleSince() === null ? 'active' : 'suspended',
				'recursive' => $watch->isRecursive(),
				'events' => $described['events'],
				'channels' => $described['channels'],
				'frequency' => $described['frequency'] === 'daily' ? 'daily at ' . $described['digestHour'] . ':00' : $described['frequency'],
				'pending' => $pending[$watch->getId()]['count'] ?? 0,
			];
		}

		if ($format === 'json') {
			$output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
			return self::SUCCESS;
		}

		if ($rows === []) {
			$output->writeln('No watched folder.');
			return self::SUCCESS;
		}
		$table = new Table($output);
		$table->setHeaders(['ID', 'User', 'Folder', 'Status', 'Sub-folders', 'Events', 'Channels', 'Frequency', 'Pending']);
		foreach ($rows as $row) {
			$table->addRow([
				$row['id'],
				$row['user'],
				$row['path'] ?? '(unreachable, file ID ' . $row['fileId'] . ')',
				$row['status'],
				$row['recursive'] ? 'yes' : 'no',
				implode(',', $row['events']),
				implode(',', $row['channels']),
				$row['frequency'],
				$row['pending'],
			]);
		}
		$table->render();
		return self::SUCCESS;
	}
}
