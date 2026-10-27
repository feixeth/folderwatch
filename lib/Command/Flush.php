<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Command;

use OCA\FolderWatch\Alert\AlertSender;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends everything that is queued now, without waiting for the quiet period or the digest hour.
 * Handy to try a set-up. The hourly cap of each user still applies.
 */
class Flush extends Command {
	public function __construct(
		private readonly AlertSender $sender,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('folderwatch:flush')
			->setDescription('Send the queued alerts now');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->sender->process(true);

		$output->writeln(sprintf('%d alert(s) sent.', $report->sent));
		if ($report->capped > 0) {
			$output->writeln(sprintf('%d held back by the hourly limit of the user.', $report->capped));
		}
		if ($report->dropped > 0) {
			$output->writeln(sprintf('%d queued item(s) dropped (folder not reachable any more, files gone, or no channel left).', $report->dropped));
		}
		if ($report->mailSkipped > 0) {
			$output->writeln(sprintf('%d e-mail(s) skipped: no e-mail address.', $report->mailSkipped));
		}
		if ($report->failed > 0) {
			$output->writeln(sprintf('<error>%d alert(s) could not be delivered and stay queued, see the log.</error>', $report->failed));
			return self::FAILURE;
		}
		return self::SUCCESS;
	}
}
