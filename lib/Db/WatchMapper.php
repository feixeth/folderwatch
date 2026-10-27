<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Watch>
 */
class WatchMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'folderwatch_watches', Watch::class);
	}

	public function find(int $id): ?Watch {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByIdAndUser(int $id, string $userId): Watch {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<Watch>
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByUserAndFile(string $userId, int $fileId): ?Watch {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Watches that cover a folder: the folder itself (recursive or not), or one of its
	 * ancestors when the watch is recursive.
	 *
	 * @param list<int> $chain file IDs of the folder and its ancestors, nearest first
	 * @return list<Watch>
	 */
	public function findCandidates(int $folderId, array $chain): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('file_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->andX(
					$qb->expr()->orX(
						$qb->expr()->isNull('recursive'),
						$qb->expr()->eq('recursive', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
					),
					$qb->expr()->in('file_id', $qb->createNamedParameter($chain, IQueryBuilder::PARAM_INT_ARRAY)),
				),
			));
		return $this->findEntities($qb);
	}

	/**
	 * @return list<Watch>
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * The next watches after an ID, to work through them in slices.
	 *
	 * @return list<Watch>
	 */
	public function findAfter(int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function countByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
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
