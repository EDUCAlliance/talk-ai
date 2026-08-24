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
            ->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter(QueuedRequest::MAX_ATTEMPTS, IQueryBuilder::PARAM_INT)))
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

    public function claimProcessingAttempt(int $id, int $expectedAttempts, int $maxAttempts, int $claimedAt): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_PROCESSING, IQueryBuilder::PARAM_STR))
            ->set('attempts', $qb->createFunction('attempts + 1'))
            ->set('error', $qb->createNamedParameter(null))
            ->set('processed_at', $qb->createNamedParameter($claimedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_PENDING, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($expectedAttempts, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    public function storeResponseReady(int $id, int $expectedAttempts, string $result): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY, IQueryBuilder::PARAM_STR))
            ->set('result', $qb->createNamedParameter($result, IQueryBuilder::PARAM_STR))
            ->set('error', $qb->createNamedParameter(null))
            ->set('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('processed_at', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_PROCESSING, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($expectedAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    public function failProcessingAttempt(int $id, int $expectedAttempts, string $error, int $processedAt, int $maxAttempts): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_FAILED, IQueryBuilder::PARAM_STR))
            ->set('error', $qb->createNamedParameter($error, IQueryBuilder::PARAM_STR))
            ->set('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT))
            ->set('processed_at', $qb->createNamedParameter($processedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_PROCESSING, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($expectedAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    public function releaseProcessingForRetry(int $id, int $expectedAttempts, string $error, int $maxAttempts): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_PENDING, IQueryBuilder::PARAM_STR))
            ->set('error', $qb->createNamedParameter($error, IQueryBuilder::PARAM_STR))
            ->set('processed_at', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_PROCESSING, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($expectedAttempts, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    public function claimResponseDeliveryAttempt(int $id, int $expectedAttempts, int $maxAttempts, int $claimedAt): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_DELIVERING, IQueryBuilder::PARAM_STR))
            ->set('attempts', $qb->createFunction('attempts + 1'))
            ->set('error', $qb->createNamedParameter(null))
            ->set('processed_at', $qb->createNamedParameter($claimedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($expectedAttempts, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    public function completeResponseDelivery(int $id, int $expectedAttempts, string $result, int $processedAt): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_COMPLETED, IQueryBuilder::PARAM_STR))
            ->set('result', $qb->createNamedParameter($result, IQueryBuilder::PARAM_STR))
            ->set('error', $qb->createNamedParameter(null))
            ->set('processed_at', $qb->createNamedParameter($processedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_DELIVERING, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY, IQueryBuilder::PARAM_STR))
            ))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($expectedAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    public function failResponseDelivery(int $id, int $expectedAttempts, string $error, int $processedAt, int $maxAttempts): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_FAILED, IQueryBuilder::PARAM_STR))
            ->set('error', $qb->createNamedParameter($error, IQueryBuilder::PARAM_STR))
            ->set('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT))
            ->set('processed_at', $qb->createNamedParameter($processedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_DELIVERING, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY, IQueryBuilder::PARAM_STR))
            ))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($expectedAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    public function releaseResponseDeliveryForRetry(int $id, int $expectedAttempts, string $error, int $maxAttempts): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY, IQueryBuilder::PARAM_STR))
            ->set('error', $qb->createNamedParameter($error, IQueryBuilder::PARAM_STR))
            ->set('processed_at', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_DELIVERING, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($expectedAttempts, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement() === 1;
    }

    public function recoverStaleResponseDeliveries(int $cutoff, int $recoveredAt, int $maxAttempts): int {
        $retry = $this->db->getQueryBuilder();
        $retry->update($this->getTableName())
            ->set('status', $retry->createNamedParameter(QueuedRequest::STATUS_RESPONSE_READY, IQueryBuilder::PARAM_STR))
            ->set('error', $retry->createNamedParameter('Previous Talk delivery outcome is unknown after lease expiration; retry scheduled', IQueryBuilder::PARAM_STR))
            ->set('processed_at', $retry->createNamedParameter(null))
            ->where($retry->expr()->eq('status', $retry->createNamedParameter(QueuedRequest::STATUS_DELIVERING, IQueryBuilder::PARAM_STR)))
            ->andWhere($retry->expr()->lt('processed_at', $retry->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
            ->andWhere($retry->expr()->lt('attempts', $retry->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));
        $recovered = $retry->executeStatement();

        $fail = $this->db->getQueryBuilder();
        $fail->update($this->getTableName())
            ->set('status', $fail->createNamedParameter(QueuedRequest::STATUS_FAILED, IQueryBuilder::PARAM_STR))
            ->set('error', $fail->createNamedParameter('Talk delivery attempts exhausted after an unknown delivery outcome', IQueryBuilder::PARAM_STR))
            ->set('processed_at', $fail->createNamedParameter($recoveredAt, IQueryBuilder::PARAM_INT))
            ->where($fail->expr()->eq('status', $fail->createNamedParameter(QueuedRequest::STATUS_DELIVERING, IQueryBuilder::PARAM_STR)))
            ->andWhere($fail->expr()->lt('processed_at', $fail->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
            ->andWhere($fail->expr()->gte('attempts', $fail->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));

        return $recovered + $fail->executeStatement();
    }

    public function failExhaustedPending(int $maxAttempts, int $failedAt): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(QueuedRequest::STATUS_FAILED, IQueryBuilder::PARAM_STR))
            ->set('error', $qb->createNamedParameter('Processing attempts exhausted before claim', IQueryBuilder::PARAM_STR))
            ->set('processed_at', $qb->createNamedParameter($failedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(QueuedRequest::STATUS_PENDING, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->gte('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
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
            ->andWhere($qb->expr()->orX(
                $qb->expr()->lt('processed_at', $qb->createNamedParameter($cutoff)),
                $qb->expr()->andX(
                    $qb->expr()->isNull('processed_at'),
                    $qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff))
                )
            ));
        
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
            } elseif ($status === QueuedRequest::STATUS_PROCESSING || $status === QueuedRequest::STATUS_DELIVERING) {
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
    public function resetStaleProcessing(int $maxAgeSeconds, int $maxAttempts = QueuedRequest::MAX_ATTEMPTS): int {
        $now = time();
        $cutoff = $now - $maxAgeSeconds;
        $retry = $this->db->getQueryBuilder();
        $retry->update($this->getTableName())
            ->set('status', $retry->createNamedParameter(QueuedRequest::STATUS_PENDING, IQueryBuilder::PARAM_STR))
            ->set('error', $retry->createNamedParameter('Processing lease expired; retry scheduled', IQueryBuilder::PARAM_STR))
            ->set('processed_at', $retry->createNamedParameter(null))
            ->where($retry->expr()->eq('status', $retry->createNamedParameter(QueuedRequest::STATUS_PROCESSING, IQueryBuilder::PARAM_STR)))
            ->andWhere($retry->expr()->orX(
                $retry->expr()->lt('processed_at', $retry->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)),
                $retry->expr()->andX(
                    $retry->expr()->isNull('processed_at'),
                    $retry->expr()->lt('created_at', $retry->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT))
                )
            ))
            ->andWhere($retry->expr()->lt('attempts', $retry->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));
        $reset = $retry->executeStatement();

        $fail = $this->db->getQueryBuilder();
        $fail->update($this->getTableName())
            ->set('status', $fail->createNamedParameter(QueuedRequest::STATUS_FAILED, IQueryBuilder::PARAM_STR))
            ->set('error', $fail->createNamedParameter('Processing attempts exhausted after lease expiration', IQueryBuilder::PARAM_STR))
            ->set('processed_at', $fail->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($fail->expr()->eq('status', $fail->createNamedParameter(QueuedRequest::STATUS_PROCESSING, IQueryBuilder::PARAM_STR)))
            ->andWhere($fail->expr()->orX(
                $fail->expr()->lt('processed_at', $fail->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)),
                $fail->expr()->andX(
                    $fail->expr()->isNull('processed_at'),
                    $fail->expr()->lt('created_at', $fail->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT))
                )
            ))
            ->andWhere($fail->expr()->gte('attempts', $fail->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));

        return $reset + $fail->executeStatement();
    }
}
