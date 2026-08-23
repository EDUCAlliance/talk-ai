<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
     * @return QueuedRequest[]
     */
    public function findResponseReady(int $limit = 10): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY)))
            ->orderBy('priority', 'ASC')
            ->addOrderBy('created_at', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    public function claimResponseDeliveryAttempt(int $id, int $maxAttempts): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('attempts', $qb->createFunction('attempts + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    public function completeResponseDelivery(int $id, string $result, int $processedAt): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_COMPLETED, IQueryBuilder::PARAM_STR))
            ->set('result', $qb->createNamedParameter($result, IQueryBuilder::PARAM_STR))
            ->set('error', $qb->createNamedParameter(null))
            ->set('processed_at', $qb->createNamedParameter($processedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_FAILED, IQueryBuilder::PARAM_STR))
            ));

        return $qb->executeStatement() === 1;
    }

    public function failResponseDelivery(int $id, string $error, int $processedAt, int $maxAttempts): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_FAILED, IQueryBuilder::PARAM_STR))
            ->set('error', $qb->createNamedParameter($error, IQueryBuilder::PARAM_STR))
            ->set('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT))
            ->set('processed_at', $qb->createNamedParameter($processedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement() === 1;
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
     * @return array{pending: int, processing: int, response_ready: int, completed: int, failed: int, total: int}
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
            'response_ready' => 0,
            'completed' => 0,
            'failed' => 0,
            'total' => 0,
        ];
        
        while ($row = $result->fetch()) {
            $status = $row['status'];
            $count = (int)$row['count'];
            if ($status === QueuedRequest::STATUS_RESPONSE_READY) {
                $stats['response_ready'] = $count;
                $stats['processing'] += $count;
            } elseif ($status === QueuedRequest::STATUS_PROCESSING) {
                $stats['processing'] += $count;
            } elseif (isset($stats[$status])) {
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
