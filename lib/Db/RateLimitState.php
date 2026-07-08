<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity for tracking LLM API rate limit state.
 * 
 * @method int getId()
 * @method void setId(int $id)
 * @method string getEndpointKey()
 * @method void setEndpointKey(string $endpointKey)
 * @method ?int getRemainingSecond()
 * @method void setRemainingSecond(?int $remainingSecond)
 * @method ?int getRemainingMinute()
 * @method void setRemainingMinute(?int $remainingMinute)
 * @method ?int getRemainingHour()
 * @method void setRemainingHour(?int $remainingHour)
 * @method ?int getRemainingDay()
 * @method void setRemainingDay(?int $remainingDay)
 * @method ?int getLimitSecond()
 * @method void setLimitSecond(?int $limitSecond)
 * @method ?int getLimitMinute()
 * @method void setLimitMinute(?int $limitMinute)
 * @method ?int getLimitHour()
 * @method void setLimitHour(?int $limitHour)
 * @method ?int getLimitDay()
 * @method void setLimitDay(?int $limitDay)
 * @method ?int getResetSecond()
 * @method void setResetSecond(?int $resetSecond)
 * @method ?int getResetMinuteAt()
 * @method void setResetMinuteAt(?int $resetMinuteAt)
 * @method ?int getResetHour()
 * @method void setResetHour(?int $resetHour)
 * @method ?int getUpdatedAt()
 * @method void setUpdatedAt(?int $updatedAt)
 */
class RateLimitState extends Entity implements JsonSerializable {
    protected ?string $endpointKey = null;
    protected ?int $remainingSecond = null;
    protected ?int $remainingMinute = null;
    protected ?int $remainingHour = null;
    protected ?int $remainingDay = null;
    protected ?int $limitSecond = null;
    protected ?int $limitMinute = null;
    protected ?int $limitHour = null;
    protected ?int $limitDay = null;
    protected ?int $resetSecond = null;
    protected ?int $resetMinuteAt = null;
    protected ?int $resetHour = null;
    protected ?int $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('endpointKey', 'string');
        $this->addType('remainingSecond', 'integer');
        $this->addType('remainingMinute', 'integer');
        $this->addType('remainingHour', 'integer');
        $this->addType('remainingDay', 'integer');
        $this->addType('limitSecond', 'integer');
        $this->addType('limitMinute', 'integer');
        $this->addType('limitHour', 'integer');
        $this->addType('limitDay', 'integer');
        $this->addType('resetSecond', 'integer');
        $this->addType('resetMinuteAt', 'integer');
        $this->addType('resetHour', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    /**
     * Check if rate limit allows a request at current time
     */
    public function canProcess(): bool {
        $effective = $this->getEffectiveRemaining();

        foreach ($effective as $remaining) {
            if ($remaining !== null && $remaining <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get effective remaining count considering window resets
     *
     * @return array{
     *     second: ?int,
     *     minute: ?int,
     *     hour: ?int,
     *     day: ?int
     * }
     */
    public function getEffectiveRemaining(): array {
        return [
            'second' => $this->getEffectiveWindowRemaining($this->limitSecond, $this->remainingSecond, $this->resetSecond),
            'minute' => $this->getEffectiveWindowRemaining($this->limitMinute, $this->remainingMinute, $this->resetMinuteAt),
            'hour' => $this->getEffectiveWindowRemaining($this->limitHour, $this->remainingHour, $this->resetHour),
            'day' => $this->getEffectiveWindowRemaining($this->limitDay, $this->remainingDay, null),
        ];
    }

    /**
     * Calculate seconds until next available slot
     */
    public function getSecondsUntilAvailable(): int {
        $now = time();
        $effective = $this->getEffectiveRemaining();
        $waits = [];

        if ($effective['second'] !== null && $effective['second'] <= 0) {
            $waits[] = $this->getWaitTime($this->resetSecond, 1, $now);
        }
        if ($effective['minute'] !== null && $effective['minute'] <= 0) {
            $waits[] = $this->getWaitTime($this->resetMinuteAt, 60, $now);
        }
        if ($effective['hour'] !== null && $effective['hour'] <= 0) {
            $waits[] = $this->getWaitTime($this->resetHour, 3600, $now);
        }
        if ($effective['day'] !== null && $effective['day'] <= 0) {
            $waits[] = 3600;
        }

        if ($waits === []) {
            return 0;
        }

        return max($waits);
    }

    public function jsonSerialize(): array {
        $effective = $this->getEffectiveRemaining();
        
        return [
            'id' => $this->id,
            'endpoint_key' => $this->endpointKey,
            'remaining_second' => $effective['second'],
            'remaining_minute' => $effective['minute'],
            'remaining_hour' => $effective['hour'],
            'remaining_day' => $effective['day'],
            'limit_second' => $this->limitSecond !== null && $this->limitSecond > 0 ? $this->limitSecond : null,
            'limit_minute' => $this->limitMinute !== null && $this->limitMinute > 0 ? $this->limitMinute : null,
            'limit_hour' => $this->limitHour !== null && $this->limitHour > 0 ? $this->limitHour : null,
            'limit_day' => $this->limitDay !== null && $this->limitDay > 0 ? $this->limitDay : null,
            'reset_second' => $this->limitSecond !== null && $this->limitSecond > 0 ? $this->resetSecond : null,
            'reset_minute_at' => $this->limitMinute !== null && $this->limitMinute > 0 ? $this->resetMinuteAt : null,
            'reset_hour' => $this->limitHour !== null && $this->limitHour > 0 ? $this->resetHour : null,
            'can_process' => $this->canProcess(),
            'seconds_until_available' => $this->getSecondsUntilAvailable(),
            'updated_at' => $this->updatedAt,
        ];
    }

    private function getEffectiveWindowRemaining(?int $limit, ?int $remaining, ?int $resetAt): ?int {
        if ($limit === null || $limit <= 0) {
            return null;
        }

        if ($resetAt !== null && $resetAt > 0 && time() >= $resetAt) {
            return $limit;
        }

        return $remaining ?? $limit;
    }

    private function getWaitTime(?int $resetAt, int $defaultWait, int $now): int {
        if ($resetAt === null || $resetAt <= 0) {
            return $defaultWait;
        }

        return max(0, $resetAt - $now);
    }
}
