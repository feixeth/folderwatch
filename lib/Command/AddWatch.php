<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Command;

use OCA\FolderWatch\Service\WatchException;
use OCA\FolderWatch\Service\WatchService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sets a watch up for somebody without going through the interface, e.g. when onboarding a customer.
 */
class AddWatch extends Command {
	public function __construct(
		private readonly WatchService $watchService,
		private readonly IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('folderwatch:add')
			->setDescription('Watch a folder on behalf of a user')
			->addArgument('user', InputArgument::REQUIRED, 'The user who gets the alerts')
			->addArgument('path', InputArgument::REQUIRED, 'Path of the folder in the files of that user, e.g. "/Documents/Inbox"')
			->addOption('no-recursive', null, InputOption::VALUE_NONE, 'Do not include sub-folders')
			->addOption('channel', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'notification and/or mail, comma separated (default: both)')
			->addOption('events', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'created, modified, deleted and/or moved_in, comma separated (default: created,moved_in)')
			->addOption('frequency', 'f', InputOption::VALUE_REQUIRED, 'immediate (default), hourly or daily')
			->addOption('digest-hour', null, InputOption::VALUE_REQUIRED, 'Hour of the daily digest, 0-23 (default: 8)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('user');
		if (!$this->userManager->userExists($uid)) {
			$output->writeln('<error>User "' . $uid . '" does not exist</error>');
			return self::FAILURE;
		}

		$options = [];
		if ($input->getOption('no-recursive')) {
			$options['recursive'] = false;
		}
		foreach (['channel' => 'channels', 'events' => 'events'] as $option => $field) {
			$values = $this->splitList($input->getOption($option));
			if ($values !== []) {
				$options[$field] = $values;
			}
		}
		if ($input->getOption('frequency') !== null) {
			$options['frequency'] = (string)$input->getOption('frequency');
		}
		if ($input->getOption('digest-hour') !== null) {
			$hour = $input->getOption('digest-hour');
			if (!is_numeric($hour) || (string)(int)$hour !== trim((string)$hour)) {
				$output->writeln('<error>--digest-hour must be a number between 0 and 23</error>');
				return self::INVALID;
			}
			$options['digestHour'] = (int)$hour;
		}

		try {
			$watch = $this->watchService->add($uid, null, (string)$input->getArgument('path'), $options);
		} catch (WatchException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		$output->writeln(sprintf(
			'Watch %d created: %s for %s (%s, events: %s, channels: %s, %s)',
			$watch['id'],
			$watch['path'] ?? '',
			$uid,
			$watch['recursive'] ? 'with sub-folders' : 'without sub-folders',
			implode(',', $watch['events']),
			implode(',', $watch['channels']),
			$watch['frequency'],
		));
		return self::SUCCESS;
	}

	/**
	 * "mail,notification" and repeated options both work.
	 *
	 * @param mixed $values
	 * @return list<string>
	 */
	private function splitList(mixed $values): array {
		$list = [];
		foreach ((array)$values as $value) {
			foreach (explode(',', (string)$value) as $item) {
				if (trim($item) !== '') {
					$list[] = trim($item);
				}
			}
		}
		return $list;
	}
}
