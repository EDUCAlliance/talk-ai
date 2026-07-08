<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<RateLimitState>
 */
class RateLimitStateMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'educai_rl_state', RateLimitState::class);
    }

    /**
     * Get or create rate limit state for an endpoint
     */
    public function getOrCreate(
        string $endpointKey,
        ?int $limitSecond = null,
        ?int $limitMinute = 30,
        ?int $limitHour = 200,
        ?int $limitDay = 1000
    ): RateLimitState {
        try {
            $state = $this->findByEndpoint($endpointKey);
            return $this->syncConfiguredWindows($state, $limitSecond, $limitMinute, $limitHour, $limitDay);
        } catch (DoesNotExistException $e) {
            $now = time();
            $secondLimit = $this->normalizeLimit($limitSecond);
            $minuteLimit = $this->normalizeLimit($limitMinute);
            $hourLimit = $this->normalizeLimit($limitHour);
            $dayLimit = $this->normalizeLimit($limitDay);

            $state = new RateLimitState();
            $state->setEndpointKey($endpointKey);
            $state->setLimitSecond($secondLimit);
            $state->setLimitMinute($minuteLimit > 0 ? $minuteLimit : null);
            $state->setLimitHour($hourLimit);
            $state->setLimitDay($dayLimit);
            $state->setRemainingSecond($secondLimit);
            $state->setRemainingMinute($minuteLimit > 0 ? $minuteLimit : null);
            $state->setRemainingHour($hourLimit);
            $state->setRemainingDay($dayLimit);
            $state->setResetSecond($secondLimit > 0 ? $now + 1 : $now);
            $state->setResetMinuteAt($minuteLimit > 0 ? $now + 60 : null);
            $state->setResetHour($hourLimit > 0 ? $now + 3600 : $now);
            $state->setUpdatedAt($now);
            return $this->insert($state);
        }
    }

    /**
     * Find rate limit state by endpoint key
     * 
     * @throws DoesNotExistException
     */
    public function findByEndpoint(string $endpointKey): RateLimitState {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('endpoint_key', $qb->createNamedParameter($endpointKey)));
        
        return $this->findEntity($qb);
    }

    /**
     * Update rate limit state from API response headers
     * 
     * @param array<string,int|string> $headers Rate limit headers from API response
     */
    public function updateFromHeaders(
        string $endpointKey,
        array $headers,
        ?int $limitSecond = null,
        ?int $limitMinute = 30,
        ?int $limitHour = 200,
        ?int $limitDay = 1000
    ): RateLimitState {
        $state = $this->getOrCreate($endpointKey, $limitSecond, $limitMinute, $limitHour, $limitDay);
        $now = time();
        $hasSecondHeaders = isset($headers['x-ratelimit-limit-second']) || isset($headers['x-ratelimit-remaining-second']);
        $hasMinuteHeaders = isset($headers['x-ratelimit-limit-minute']) || isset($headers['x-ratelimit-remaining-minute']);
        $standardLimit = isset($headers['ratelimit-limit']) ? (int)$headers['ratelimit-limit'] : null;
        $standardRemaining = isset($headers['ratelimit-remaining']) ? (int)$headers['ratelimit-remaining'] : null;

        if (isset($headers['x-ratelimit-remaining-second'])) {
            $state->setRemainingSecond((int)$headers['x-ratelimit-remaining-second']);
        }
        if (!$hasSecondHeaders && ($hasMinuteHeaders || $standardLimit !== null || $standardRemaining !== null)) {
            $state->setLimitSecond(0);
            $state->setRemainingSecond(0);
            $state->setResetSecond($now);
        }

        $remainingMinute = $headers['x-ratelimit-remaining-minute'] ?? null;
        if ($remainingMinute === null && !$hasSecondHeaders && $standardRemaining !== null) {
            $remainingMinute = $standardRemaining;
        }
        if ($remainingMinute !== null) {
            $state->setRemainingMinute((int)$remainingMinute);
        }

        if (isset($headers['x-ratelimit-remaining-hour'])) {
            $state->setRemainingHour((int)$headers['x-ratelimit-remaining-hour']);
        }
        if (isset($headers['x-ratelimit-remaining-day'])) {
            $state->setRemainingDay((int)$headers['x-ratelimit-remaining-day']);
        }

        if (isset($headers['x-ratelimit-limit-second'])) {
            $state->setLimitSecond((int)$headers['x-ratelimit-limit-second']);
        }

        $minuteLimit = $headers['x-ratelimit-limit-minute'] ?? null;
        if ($minuteLimit === null && !$hasSecondHeaders && $standardLimit !== null) {
            $minuteLimit = $standardLimit;
        }
        if ($minuteLimit !== null) {
            $state->setLimitMinute((int)$minuteLimit);
        }

        if (isset($headers['x-ratelimit-limit-hour'])) {
            $state->setLimitHour((int)$headers['x-ratelimit-limit-hour']);
        }
        if (isset($headers['x-ratelimit-limit-day'])) {
            $state->setLimitDay((int)$headers['x-ratelimit-limit-day']);
        }

        if (isset($headers['ratelimit-reset'])) {
            $resetSeconds = max(1, (int)$headers['ratelimit-reset']);
            if (($state->getLimitSecond() ?? 0) > 0) {
                $state->setResetSecond($now + $resetSeconds);
            }
            if (($state->getLimitMinute() ?? 0) > 0) {
                $state->setResetMinuteAt($now + $resetSeconds);
            }
        } elseif (($state->getLimitSecond() ?? 0) > 0) {
            $state->setResetSecond($now + 1);
        }

        if (($state->getLimitMinute() ?? 0) > 0 && ($state->getResetMinuteAt() ?? 0) <= 0) {
            $state->setResetMinuteAt($now + 60);
        }

        if (($state->getLimitHour() ?? 0) > 0) {
            $state->setResetHour($now + 3600);
        }

        $state->setUpdatedAt($now);

        return $this->update($state);
    }

    /**
     * Decrement remaining count (optimistic, before making request)
     */
    public function decrementRemaining(
        string $endpointKey,
        ?int $limitSecond = null,
        ?int $limitMinute = 30,
        ?int $limitHour = 200,
        ?int $limitDay = 1000
    ): RateLimitState {
        $state = $this->getOrCreate($endpointKey, $limitSecond, $limitMinute, $limitHour, $limitDay);
        $now = time();

        if (($state->getLimitSecond() ?? 0) > 0 && $now >= ($state->getResetSecond() ?? 0)) {
            $state->setRemainingSecond($state->getLimitSecond());
            $state->setResetSecond($now + 1);
        }
        if (($state->getLimitMinute() ?? 0) > 0 && $now >= ($state->getResetMinuteAt() ?? 0)) {
            $state->setRemainingMinute($state->getLimitMinute());
            $state->setResetMinuteAt($now + 60);
        }
        if (($state->getLimitHour() ?? 0) > 0 && $now >= ($state->getResetHour() ?? 0)) {
            $state->setRemainingHour($state->getLimitHour());
            $state->setResetHour($now + 3600);
        }

        if (($state->getLimitSecond() ?? 0) > 0) {
            $state->setRemainingSecond(max(0, ($state->getRemainingSecond() ?? $state->getLimitSecond() ?? 0) - 1));
        }
        if (($state->getLimitMinute() ?? 0) > 0) {
            $state->setRemainingMinute(max(0, ($state->getRemainingMinute() ?? $state->getLimitMinute() ?? 0) - 1));
        }
        if (($state->getLimitHour() ?? 0) > 0) {
            $state->setRemainingHour(max(0, ($state->getRemainingHour() ?? $state->getLimitHour() ?? 0) - 1));
        }
        if (($state->getLimitDay() ?? 0) > 0) {
            $state->setRemainingDay(max(0, ($state->getRemainingDay() ?? $state->getLimitDay() ?? 0) - 1));
        }
        $state->setUpdatedAt($now);

        return $this->update($state);
    }

    /**
     * Reset all rate limit states (for testing or manual reset)
     */
    public function resetAll(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())->executeStatement();
    }

    private function normalizeLimit(?int $limit): int {
        return $limit !== null && $limit > 0 ? $limit : 0;
    }

    private function syncConfiguredWindows(
        RateLimitState $state,
        ?int $limitSecond,
        ?int $limitMinute,
        ?int $limitHour,
        ?int $limitDay
    ): RateLimitState {
        $now = time();
        $updated = false;

        $secondLimit = $this->normalizeLimit($limitSecond);
        if (($state->getLimitSecond() ?? 0) <= 0 && $secondLimit > 0) {
            $state->setLimitSecond($secondLimit);
            if ($state->getRemainingSecond() === null) {
                $state->setRemainingSecond($secondLimit);
            }
            if (($state->getResetSecond() ?? 0) <= 0) {
                $state->setResetSecond($now + 1);
            }
            $updated = true;
        }

        $minuteLimit = $this->normalizeLimit($limitMinute);
        if (($state->getLimitMinute() ?? 0) <= 0 && $minuteLimit > 0) {
            $state->setLimitMinute($minuteLimit);
            if ($state->getRemainingMinute() === null) {
                $state->setRemainingMinute($minuteLimit);
            }
            if (($state->getResetMinuteAt() ?? 0) <= 0) {
                $state->setResetMinuteAt($now + 60);
            }
            $updated = true;
        }

        $hourLimit = $this->normalizeLimit($limitHour);
        if (($state->getLimitHour() ?? 0) <= 0 && $hourLimit > 0) {
            $state->setLimitHour($hourLimit);
            if ($state->getRemainingHour() === null) {
                $state->setRemainingHour($hourLimit);
            }
            if (($state->getResetHour() ?? 0) <= 0) {
                $state->setResetHour($now + 3600);
            }
            $updated = true;
        } elseif (($state->getLimitHour() ?? 0) > 0 && ($state->getResetHour() ?? 0) <= 0) {
            $state->setResetHour($now + 3600);
            $updated = true;
        }

        $dayLimit = $this->normalizeLimit($limitDay);
        if (($state->getLimitDay() ?? 0) <= 0 && $dayLimit > 0) {
            $state->setLimitDay($dayLimit);
            if ($state->getRemainingDay() === null) {
                $state->setRemainingDay($dayLimit);
            }
            $updated = true;
        }

        if (($state->getLimitSecond() ?? 0) > 0 && ($state->getResetSecond() ?? 0) <= 0) {
            $state->setResetSecond($now + 1);
            $updated = true;
        }
        if (($state->getLimitMinute() ?? 0) > 0 && ($state->getResetMinuteAt() ?? 0) <= 0) {
            $state->setResetMinuteAt($now + 60);
            $updated = true;
        }

        if (!$updated) {
            return $state;
        }

        $state->setUpdatedAt($now);
        return $this->update($state);
    }
}
