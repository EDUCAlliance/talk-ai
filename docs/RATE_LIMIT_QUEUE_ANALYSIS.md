# LLM Rate Limiting Queue

> **Status**: Current implementation notes and remaining follow-ups

This document summarizes the LLM rate limiting and request queue system in Talk AI, including resolved bugs and remaining areas for improvement.

## Overview

The rate limiting system consists of:
- **`RateLimitService`**: Core service for rate limit tracking and request queuing
- **`RateLimitState` / `RateLimitStateMapper`**: Database entities for tracking rate limit state
- **`QueuedRequest` / `QueuedRequestMapper`**: Database entities for the request queue
- **`ProcessQueuedRequestsJob`**: Background job to process queued requests
- **Database tables**: `educai_rl_state` and `educai_queue`

## Background Job Registration

**Location**: `appinfo/info.xml`

**Previous behavior**: The `ProcessQueuedRequestsJob` was not registered in `info.xml`.

**Current behavior**: Background jobs are registered in `info.xml`:

```xml
<background-jobs>
    <job>OCA\EducAI\Jobs\RefreshCatalogueEmbeddingsJob</job>
    <job>OCA\EducAI\Jobs\ProcessQueuedRequestsJob</job>
    <job>OCA\EducAI\Jobs\CleanupConversationsJob</job>
    <job>OCA\EducAI\Jobs\CleanupOrphanedSourcesJob</job>
</background-jobs>
```

Dynamic job registration was also removed from `Application::boot()`.

---

## Failed Request Retry Mechanism

**Location**: `lib/Jobs/ProcessQueuedRequestsJob.php` lines 199-224

**Previous behavior**: When a request failed but still had retry attempts available, it was marked as `STATUS_FAILED` but never picked up again because the job only queries for `STATUS_PENDING` requests.

**Current behavior**: `RateLimitService::markForRetry()` resets the request status to `STATUS_PENDING` while preserving the error message. The job calls this instead of `markFailed()` for retryable requests:

```php
// RateLimitService.php - new method
public function markForRetry(QueuedRequest $request, string $error): QueuedRequest {
    $request->setStatus(QueuedRequest::STATUS_PENDING);
    $request->setError($error);
    return $this->queuedRequestMapper->update($request);
}

// ProcessQueuedRequestsJob.php - updated logic
if ($request->getAttempts() < self::MAX_RETRY_ATTEMPTS) {
    $this->rateLimitService->markForRetry($request, $error);
    // Request will be picked up again on next job run
}
```

---

## Medium Issue: Double-Increment of Attempts

**Location**: `lib/Jobs/ProcessQueuedRequestsJob.php`

**Problem**: The attempt counter is incremented twice for failing requests:
1. Once in `markProcessing()` (line 149)
2. Once again in `resetStaleProcessing()` if the request times out (lines 192-194 in `QueuedRequestMapper`)

For a normal failure, this is fine. But if a request times out AND then fails when retried, it may hit the max attempts limit faster than intended.

---

## Other Background Jobs

**Previous behavior**: Other jobs were missing from `info.xml` and added dynamically in `boot()`.

**Current behavior**: All jobs are registered in `info.xml`; `Application::boot()` does not register jobs dynamically.

---

## Medium Issue: Queue Stats Calculation After Processing

**Location**: `lib/Jobs/ProcessQueuedRequestsJob.php` line 132-133

**Problem**: The remaining pending count is calculated incorrectly:
```php
'remaining_pending' => $stats['pending'] - $processedCount,
```

The `$stats` variable is fetched **before** processing, so this subtraction is correct conceptually, but doesn't account for:
- Requests that expired (marked failed)
- Requests that were reset from stale processing

This is minor but could lead to misleading logs.

---

## Correctly Implemented

1. **Rate limit state tracking from API headers** - Works correctly
2. **Database schema** - Tables are properly designed
3. **Settings integration** - Rate limit settings are properly stored and retrieved
4. **UI for status display** - AdminSettings.vue shows queue status
5. **Queue priority ordering** - Lower priority = higher priority, ordered by creation time
6. **Stale request handling** - `resetStaleProcessing()` recovers stuck requests
7. **Cleanup of old requests** - `cleanup()` method removes old completed/failed entries

---

## Summary of Fixes Applied

| Priority | Issue | File | Status |
|----------|-------|------|--------|
| Critical | ProcessQueuedRequestsJob not in info.xml | `appinfo/info.xml` | Fixed |
| Critical | Failed retries never picked up | `ProcessQueuedRequestsJob.php`, `RateLimitService.php` | Fixed |
| Medium | Other jobs not in info.xml | `appinfo/info.xml` | Fixed |
| Low | Queue stats logging accuracy | `ProcessQueuedRequestsJob.php` | Minor, not fixed |

---

## Testing Recommendations

1. **Enable rate limiting** in admin settings
2. **Trigger rate limit** by sending many requests quickly
3. **Verify queuing**: Check `educai_queue` table for pending requests
4. **Run cron manually**: `php occ background-job:execute OCA\\EducAI\\Jobs\\ProcessQueuedRequestsJob`
5. **Verify processing**: Check that queued requests get processed
6. **Test retry**: Simulate a failure and verify the request gets retried
