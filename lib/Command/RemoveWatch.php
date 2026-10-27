<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Command;

use OCA\FolderWatch\Service\WatchService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveWatch extends Command {
	public function __construct(
		private readonly WatchService $watchService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('folderwatch:remove')
			->setDescription('Stop a watch and drop its pending alerts')
			->addArgument('id', InputArgument::REQUIRED, 'ID of the watch, see folderwatch:list');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (string)$input->getArgument('id');
		if (!ctype_digit($id)) {
			$output->writeln('<error>The ID must be a number, see folderwatch:list</error>');
			return self::INVALID;
		}
		if (!$this->watchService->removeById((int)$id)) {
			$output->writeln('<error>There is no watch ' . $id . '</error>');
			return self::FAILURE;
		}
		$output->writeln('Watch ' . $id . ' removed.');
		return self::SUCCESS;
	}
}
