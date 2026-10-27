<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Db;

use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * One row per delivered batch: the count of the last hour is the anti-spam cap.
 */
class SendLogMapper {
	private const TABLE = 'folderwatch_sends';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @throws Exception
	 */
	public function record(string $userId, int $timestamp): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'sent_at' => $qb->createNamedParameter($timestamp),
			])
			->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function countSince(string $userId, int $timestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('sent_at', $qb->createNamedParameter($timestamp)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @throws Exception
	 */
	public function deleteOlderThan(int $timestamp): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('sent_at', $qb->createNamedParameter($timestamp)))
			->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}
}
