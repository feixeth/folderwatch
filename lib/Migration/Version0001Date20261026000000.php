<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date20261026000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('folderwatch_watches')) {
			$table = $schema->createTable('folderwatch_watches');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('recursive', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			// bitmask: 1 created, 2 modified, 4 deleted, 8 moved in
			$table->addColumn('events', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			// bitmask: 1 notification, 2 mail
			$table->addColumn('channels', Types::INTEGER, ['notnull' => true, 'default' => 3]);
			// immediate | hourly | daily
			$table->addColumn('frequency', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'immediate']);
			// local hour (0-23) of the daily digest
			$table->addColumn('digest_hour', Types::INTEGER, ['notnull' => true, 'default' => 8]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_sent_at', Types::BIGINT, ['notnull' => false]);
			// set while the folder is not reachable (trash, unshared); NULL when reachable
			$table->addColumn('inaccessible_since', Types::BIGINT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'file_id'], 'fw_watches_user_file_uq');
			$table->addIndex(['file_id'], 'fw_watches_file_idx');
		}

		if (!$schema->hasTable('folderwatch_queue')) {
			$table = $schema->createTable('folderwatch_queue');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('watch_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			// 1 created, 2 modified, 4 deleted, 8 moved in
			$table->addColumn('event', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			// path relative to the watched folder, display only (never used for matching)
			$table->addColumn('path_hint', Types::STRING, ['notnull' => false, 'length' => 1024]);
			// absolute links worked out when the event happened, in the web request that caused it:
			// to the file (NULL for a removal or when no trustworthy address was known), and to the watched folder
			$table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 1024]);
			$table->addColumn('folder_url', Types::STRING, ['notnull' => false, 'length' => 1024]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'created_at'], 'fw_queue_user_created_idx');
			$table->addIndex(['watch_id'], 'fw_queue_watch_idx');
		}

		if (!$schema->hasTable('folderwatch_sends')) {
			$table = $schema->createTable('folderwatch_sends');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('sent_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'sent_at'], 'fw_sends_user_sent_idx');
		}

		return $schema;
	}
}
