<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<QueuedRequest>
 */
class QueuedRequestMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'educai_queue', QueuedRequest::class);
    }

    /**
     * Find a queued request by ID
     * 
     * @throws DoesNotExistException
     */
    public function findById(int $id): QueuedRequest {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        
        return $this->findEntity($qb);
    }

    /**
     * Get pending requests ordered by priority and creation time
     * 
     * @return QueuedRequest[]
     */
    public function findPending(int $limit = 10): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_PENDING)))
            ->orderBy('priority', 'ASC')
            ->addOrderBy('created_at', 'ASC')
            ->setMaxResults($limit);
        
        return $this->findEntities($qb);
    }

    /**
     * Get requests that are stale (processing for too long)
     * 
     * @return QueuedRequest[]
     */
    public function findStaleProcessing(int $maxAgeSeconds = 300): array {
        $qb = $this->db->getQueryBuilder();
        $cutoff = time() - $maxAgeSeconds;
        
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_PROCESSING)))
            ->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff)));
        
        return $this->findEntities($qb);
    }

    /**
     * Get requests that failed but can be retried
     * 
     * @return QueuedRequest[]
     */
    public function findRetryable(int $maxAttempts = 3, int $limit = 10): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_FAILED)))
            ->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter($maxAttempts)))
            ->orderBy('priority', 'ASC')
            ->addOrderBy('created_at', 'ASC')
            ->setMaxResults($limit);
        
        return $this->findEntities($qb);
    }

    /**
     * Get completed requests for a room
     * 
     * @return QueuedRequest[]
     */
    public function findCompletedByRoom(string $roomToken, int $limit = 10): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_COMPLETED)))
            ->orderBy('processed_at', 'DESC')
            ->setMaxResults($limit);
        
        return $this->findEntities($qb);
    }

    /**
     * Get total count of pending requests
     */
    public function countPending(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_PENDING)));
        
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        
        return (int)($row['count'] ?? 0);
    }

    /**
     * Get queue statistics
     * 
     * @return array{pending: int, processing: int, completed: int, failed: int, total: int}
     */
    public function getQueueStats(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('status', $qb->func()->count('*', 'count'))
            ->from($this->getTableName())
            ->groupBy('status');
        
        $result = $qb->executeQuery();
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'total' => 0,
        ];
        
        while ($row = $result->fetch()) {
            $status = $row['status'];
            $count = (int)$row['count'];
            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
            $stats['total'] += $count;
        }
        $result->closeCursor();
        
        return $stats;
    }

    /**
     * Delete old completed requests
     */
    public function cleanupCompleted(int $maxAgeSeconds = 86400): int {
        $qb = $this->db->getQueryBuilder();
        $cutoff = time() - $maxAgeSeconds;
        
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_COMPLETED)))
            ->andWhere($qb->expr()->lt('processed_at', $qb->createNamedParameter($cutoff)));
        
        return $qb->executeStatement();
    }

    /**
     * Delete old failed requests that exceeded retry limit
     */
    public function cleanupFailed(int $maxAttempts = 3, int $maxAgeSeconds = 86400): int {
        $qb = $this->db->getQueryBuilder();
        $cutoff = time() - $maxAgeSeconds;
        
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_FAILED)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->gte('attempts', $qb->createNamedParameter($maxAttempts)),
                    $qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff))
                )
            );
        
        return $qb->executeStatement();
    }

    /**
     * Reset stale processing requests back to pending
     */
    public function resetStaleProcessing(int $maxAgeSeconds = 300): int {
        $stale = $this->findStaleProcessing($maxAgeSeconds);
        $count = 0;
        
        foreach ($stale as $request) {
            $request->setStatus(QueuedRequest::STATUS_PENDING);
            $request->incrementAttempts();
            $this->update($request);
            $count++;
        }
        
        return $count;
    }
}

