<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<QueueItem>
 */
class QueueItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'folderwatch_queue', QueueItem::class);
	}

	/**
	 * Insert many rows with a few multi-row statements instead of one round trip per row.
	 *
	 * @param list<QueueItem> $items
	 * @throws Exception
	 */
	public function insertBatch(array $items): void {
		if ($items === []) {
			return;
		}
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_ORACLE) {
			$this->db->beginTransaction();
			try {
				foreach ($items as $item) {
					$this->insert($item);
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return;
		}

		foreach (array_chunk($items, 100) as $chunk) {
			$params = [];
			foreach ($chunk as $item) {
				array_push(
					$params,
					$item->getWatchId(),
					$item->getUserId(),
					$item->getFileId(),
					$item->getEvent(),
					$item->getActorUid(),
					$item->getPathHint(),
					$item->getUrl(),
					$item->getFolderUrl(),
					$item->getCreatedAt(),
				);
			}
			$sql = 'INSERT INTO `*PREFIX*' . $this->getTableName() . '`'
				. ' (`watch_id`, `user_id`, `file_id`, `event`, `actor_uid`, `path_hint`, `url`, `folder_url`, `created_at`) VALUES '
				. implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'));
			$this->db->executeStatement($sql, $params);
		}
	}

	/**
	 * Pending rows per watch: count, oldest and newest creation time.
	 *
	 * @return array<int, array{count: int, oldest: int, newest: int}>
	 * @throws Exception
	 */
	public function getPendingStats(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('watch_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->selectAlias($qb->func()->min('created_at'), 'oldest')
			->selectAlias($qb->func()->max('created_at'), 'newest')
			->from($this->getTableName())
			->groupBy('watch_id');
		$result = $qb->executeQuery();
		$stats = [];
		while ($row = $result->fetch()) {
			$stats[(int)$row['watch_id']] = [
				'count' => (int)$row['cnt'],
				'oldest' => (int)$row['oldest'],
				'newest' => (int)$row['newest'],
			];
		}
		$result->closeCursor();
		return $stats;
	}

	/**
	 * Oldest rows first, so a huge backlog is worked off over several runs.
	 *
	 * @return list<QueueItem>
	 * @throws Exception
	 */
	public function findByWatch(int $watchId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $ids
	 * @throws Exception
	 */
	public function deleteByIds(array $ids): void {
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * Safety net for alerts that could never be delivered.
	 *
	 * @throws Exception
	 */
	public function deleteOlderThan(int $timestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp)));
		return $qb->executeStatement();
	}

	/**
	 * Rows whose watch does not exist any more.
	 *
	 * @throws Exception
	 */
	public function deleteOrphans(): int {
		$watches = $this->db->getQueryBuilder();
		$watches->select('id')->from('folderwatch_watches');

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->notIn('watch_id', $qb->createFunction('(' . $watches->getSQL() . ')')));
		return $qb->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function deleteByWatch(int $watchId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId)));
		$qb->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
