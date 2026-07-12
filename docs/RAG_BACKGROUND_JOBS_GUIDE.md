# RAG Background Jobs & Troubleshooting Guide

## Problem: Documents Stuck in "PENDING" Status

This guide explains how the RAG (Retrieval-Augmented Generation) background job system works and how to troubleshoot when documents get stuck in "PENDING" status.

---

## Document Conversion (Docling)

The RAG system now supports **PDF and Office document conversion** via the Docling API. This enables indexing of binary documents that cannot be read as plain text.

### Supported File Types

When Docling is enabled, the following file types can be ingested:

| Format | Extension | MIME Type |
|--------|-----------|-----------|
| PDF | `.pdf` | `application/pdf` |
| Word | `.docx`, `.doc` | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` |
| PowerPoint | `.pptx`, `.ppt` | `application/vnd.openxmlformats-officedocument.presentationml.presentation` |
| Excel | `.xlsx`, `.xls` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |
| Images (OCR) | `.png`, `.jpg`, `.jpeg`, `.tiff`, `.bmp` | `image/*` |

### Enabling Docling

1. Open the Talk AI app in Nextcloud
2. Scroll to **Administrator Settings**
3. Find the **Document Conversion (Docling)** section
4. Check **Enable Document Conversion**
5. (Optional) Set a custom API endpoint (defaults to Academic Cloud)
6. (Optional) Set a dedicated Docling API key if the conversion endpoint uses a different credential
7. Click **Save Settings**

**Note:** Docling uses its dedicated API key when configured. If the Docling API key is blank, it falls back to the main API key configured for the chat API. Make sure the effective key supports the `/v1/documents/convert` endpoint.

### How It Works

1. When a file is attached to a bot, the system checks if it's a supported binary format
2. If Docling is enabled and the file is supported, it's uploaded to the Docling API
3. The API converts the document to Markdown format
4. The Markdown text is then chunked and embedded like any other text file

### Troubleshooting Docling

**"Docling document conversion is disabled"**
- Enable Docling in Admin Settings

**"API key not configured for Docling"**
- Configure either the dedicated Docling API key or the main API key in Admin Settings

**"Failed to convert document"**
- Check that the file type is supported
- Verify the API endpoint is reachable
- Check Nextcloud logs for detailed error messages

**Large documents timing out**
- The default timeout is 120 seconds
- Very large documents may require increased PHP timeout settings

---

## Common Issues: Background Job Errors

### Issue 1: Background Job Registration Error

If you see this error in your logs:
```
Call to undefined method OCP\AppFramework\Bootstrap\IRegistrationContext::registerJob()
```

**Cause**: Older code tried to call `$context->registerJob()` in `lib/AppInfo/Application.php`; this method does not exist in Nextcloud's API.

**Current behavior**: Background jobs are declared in `appinfo/info.xml`; constructor dependencies are registered in the app bootstrap.

### Issue 2: Job Constructor Missing Parent Call

If you see this error:
```
Error while running background job OCA\EducAI\Jobs\ReindexBotSourceJob
Typed property OCP\BackgroundJob\Job::$time must not be accessed before initialization
```

**Cause**: The `ReindexBotSourceJob` constructor was missing `parent::__construct()` call, which initializes required properties.

**Fix**: Add `parent::__construct();` as the first line in the constructor:
```php
public function __construct(RagIngestionService $ingestionService, LoggerInterface $logger) {
    parent::__construct();  // ← This line is required!
    $this->ingestionService = $ingestionService;
    $this->logger = $logger;
}
```

Current job constructors call the parent constructor.

---

## How RAG Background Job Queuing Works

### 1. Job Enqueueing Flow
When you attach a file/folder to a bot:

1. **Frontend** (`BotForm.vue`) → sends file/folder nodeId to backend
2. **RagController::store** → creates `BotSource` record with status='pending'
3. **RagController** → calls `RagIngestionService::enqueueSource()`
4. **RagIngestionService** → adds job to queue:
   ```php
   $this->jobList->add(ReindexBotSourceJob::class, [
       'sourceId' => $sourceId,
       'force' => $force,
   ]);
   ```
5. Job is now in the `oc_jobs` table, waiting for cron execution

### 2. Cron Execution
Your Nextcloud uses `backgroundjobs_mode = cron`, which means:

- **You MUST have a system crontab** configured
- The cron job calls Nextcloud's cron.php every 5 minutes
- Without cron, background jobs **will never execute**

### 3. Job Processing
When cron runs:
1. Nextcloud's job system picks up pending `ReindexBotSourceJob` instances
2. Job calls `RagIngestionService::ingestSourceById()`
3. Service performs:
   - Resolves file/folder using Nextcloud's file system API
   - If file/folder was deleted: cleans up embeddings and marks source as error
   - Extracts text content from files
   - Chunks text with configurable size and overlap
   - Generates embeddings via embedding API
   - Stores embeddings in database
4. Updates source status to 'ready' or 'error' with error message

### 4. Orphaned Source Cleanup
A separate `CleanupOrphanedSourcesJob` runs every **6 hours** to:
1. Scan all RAG sources in the database
2. Check if referenced files/folders still exist
3. Clean up embeddings for sources pointing to deleted files
4. Mark orphaned sources with an error status

This ensures that even if a file is deleted without manually reindexing, the orphaned embeddings will be cleaned up automatically.

---

## Required Setup: Configure Cron

Since Nextcloud is configured with `backgroundjobs_mode = cron`, **you MUST have a cronjob running**.

### Check Current Configuration
```bash
sudo -u www-data php occ config:app:get core backgroundjobs_mode
```

Expected output: `cron`

### Option 1: System Crontab (Simple)

Edit your system crontab:
```bash
crontab -e
```

Add this line (runs every 5 minutes):
```bash
*/5 * * * * sudo -u www-data php /var/www/html/cron.php
```

Save and exit. Verify it's registered:
```bash
crontab -l
```

### Option 2: Systemd Timer (Recommended for Production)

**Create the service file:**
```bash
sudo nano /etc/systemd/system/nextcloud-cron.service
```

Content:
```ini
[Unit]
Description=Nextcloud Background Jobs
After=docker.service

[Service]
Type=oneshot
ExecStart=/usr/bin/sudo -u www-data php /var/www/html/cron.php
User=educ-dev
```

**Create the timer file:**
```bash
sudo nano /etc/systemd/system/nextcloud-cron.timer
```

Content:
```ini
[Unit]
Description=Run Nextcloud cron every 5 minutes

[Timer]
OnBootSec=5min
OnUnitActiveSec=5min

[Install]
WantedBy=timers.target
```

**Enable and start:**
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now nextcloud-cron.timer
sudo systemctl status nextcloud-cron.timer
```

**Check timer logs:**
```bash
journalctl -u nextcloud-cron.service -f
```

---

## Diagnostic Commands

### Check Logs

**View live logs:**
```bash
tail -f /path/to/nextcloud/data/nextcloud.log
```

**Filter for Talk AI entries:**
```bash
tail -100 /path/to/nextcloud/data/nextcloud.log | grep -i educai
```

**Check for errors:**
```bash
tail -500 /path/to/nextcloud/data/nextcloud.log | grep -i "error\|exception"
```

**Watch for RAG processing:**
```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep -i "educai\|reindex\|embedding"
```

### Check Background Job Status

**List all background jobs:**
```bash
sudo -u www-data php occ background-job:list
```

**Check background job mode:**
```bash
sudo -u www-data php occ config:app:get core backgroundjobs_mode
```

**Set background job mode to cron (if not already):**
```bash
sudo -u www-data php occ config:app:set core backgroundjobs_mode --value=cron
```

### Manually Trigger Background Jobs

**Run ONE background job cycle:**
```bash
sudo -u www-data php /var/www/html/cron.php
```

**Run worker (processes multiple jobs continuously):**
```bash
sudo -u www-data php occ background-job:worker
```

**Manually trigger job worker and watch output:**
```bash
sudo -u www-data php occ background-job:worker 2>&1 | head -50
```

### Check App Status

**Check if app is enabled:**
```bash
sudo -u www-data php occ app:list | grep educai
```

**Reload the app (after code changes):**
```bash
sudo -u www-data php occ app:enable educai
sudo -u www-data php occ upgrade --no-interaction
```

**Check migration status:**
```bash
sudo -u www-data php occ migrations:status educai
```

---

## Troubleshooting Steps

### Documents Stuck in "PENDING"

1. **Verify cron is configured:**
   ```bash
   crontab -l
   ```
   You should see the Nextcloud cron job listed.

2. **Manually trigger cron:**
   ```bash
   sudo -u www-data php /var/www/html/cron.php
   ```

3. **Watch logs for processing:**
   ```bash
   tail -f /path/to/nextcloud/data/nextcloud.log | grep -i educai
   ```

4. **Check for errors in the source:**
   - Go to bot edit page
   - Look at the source's error message (displayed below the status if failed)

5. **Manually trigger reindex:**
   - Click the "Reindex" button on the pending source
   - Wait 5 minutes or manually trigger cron
   - Refresh the page to see updated status

### Common Error Messages

**"RAG is disabled by the administrator"**
- Solution: Admin must enable RAG in Admin Settings

**"No readable files found for source"**
- Solution: Check if the file/folder still exists and is readable
- Verify file permissions in Nextcloud

**"File or folder no longer exists"**
- The source file/folder was deleted from Nextcloud
- Embeddings are automatically cleaned up when you click "Reindex" or when the cleanup job runs
- You can safely remove the source from the bot's configuration
- A background cleanup job also runs every 6 hours to detect and clean up orphaned sources

**Embedding API errors (401, 403, 500)**
- Solution: Check embedding API endpoint and API key in Admin Settings
- Verify the embedding model name is correct
- Test the API endpoint manually

**"Failed to extract text"**
- Solution: Check if the file type is supported. Without Docling: `txt`, `md`, `json`, `xml`. With Docling enabled: PDF/Office formats and images are supported as well.
- Large files may require more PHP memory

**"php_network_getaddresses: getaddrinfo for blackfire failed"**
- This is harmless - it's the Blackfire profiler trying to connect
- Can be ignored or disabled in PHP configuration
- Does not affect RAG functionality

### Check RAG Configuration

**Via Admin UI:**
1. Open Talk AI app
2. Scroll to "Administrator Settings"
3. Expand "Retrieval-Augmented Generation" section
4. Verify:
   - ✅ Enable RAG is checked
   - API endpoints are correct
   - API keys are set
   - Model names are correct

**Via Command Line:**
```bash
sudo -u www-data php occ config:list educai
```

---

## Performance Considerations

### Job Execution Timing
- Background jobs run every **5 minutes** by default
- Each job processes **one source** at a time
- Large folders may take multiple job cycles

### Resource Requirements
- **PHP Memory**: 512MB+ recommended for large files
- **Embedding API**: Rate limits may slow processing
- **Database**: Proper indexes are critical (auto-created by migrations)

### Recommended Limits
- Max **100 files** per bot
- Max **10MB** per file
- Total embeddings < **100,000** per bot

---

## Advanced: Direct Database Inspection

⚠️ **Use with caution** - for debugging only.

### Check pending sources:
```bash
# Access Nextcloud database container (if separate)
docker exec -it <db-container-name> mysql -u nextcloud -p nextcloud

# Or if database is in the same container:
mysql -u <dbuser> -p <dbname>   # credentials from config.php

# Query pending sources
SELECT * FROM oc_educai_bot_sources WHERE status = 'pending';

# Query embeddings count per bot
SELECT bot_id, COUNT(*) as embedding_count FROM oc_educai_embeddings GROUP BY bot_id;
```

### Check background jobs table:
```sql
SELECT id, class, last_run, argument 
FROM oc_jobs 
WHERE class LIKE '%EducAI%' 
ORDER BY id DESC 
LIMIT 20;
```

### Check for orphaned sources (files that no longer exist):
```sql
SELECT bs.id, bs.bot_id, bs.node_id, bs.status, bs.error_message,
       (SELECT COUNT(*) FROM oc_educai_embeddings e WHERE e.source_id = bs.id) as embedding_count
FROM oc_educai_bot_sources bs
WHERE bs.status = 'error' 
  AND bs.error_message LIKE '%no longer exists%';
```

---

## Testing the Fix

After applying fixes:

1. **Run upgrade checks after deploying code changes:**
   ```bash
   sudo -u www-data php occ app:enable educai
   sudo -u www-data php occ upgrade --no-interaction
   ```

2. **Click "Reindex" on a pending document** in the bot edit page

3. **Manually trigger cron:**
   ```bash
   sudo -u www-data php /var/www/html/cron.php
   ```

4. **Watch logs:**
   ```bash
   tail -f /path/to/nextcloud/data/nextcloud.log | grep -i educai
   ```

5. **Refresh bot edit page** after 1-2 minutes to see updated status

Expected flow:
- Status: PENDING → READY (success)
- Status: PENDING → ERROR (with error message explaining the issue)

**Note:** If the file/folder was deleted from Nextcloud, clicking "Reindex" will:
1. Detect that the file no longer exists
2. Clean up all embeddings for that source
3. Set status to ERROR with message "File or folder no longer exists"
4. You can then safely remove the source from the bot

---

## Quick Reference: OCC Commands

```bash
# Background jobs
sudo -u www-data php occ background-job:list
sudo -u www-data php occ background-job:worker

# App management
sudo -u www-data php occ app:list
sudo -u www-data php occ app:enable educai
sudo -u www-data php occ upgrade --no-interaction

# Configuration
sudo -u www-data php occ config:app:get core backgroundjobs_mode
sudo -u www-data php occ config:list educai

# Migrations
sudo -u www-data php occ migrations:status educai

# Cron
sudo -u www-data php /var/www/html/cron.php
```

---

## Support

If documents remain stuck after following this guide:

1. **Check the logs** for specific error messages
2. **Verify RAG is enabled** in admin settings
3. **Test embedding API** manually with curl
4. **Check file permissions** in Nextcloud
5. **Increase PHP memory limit** if processing large files

For persistent issues, provide:
- Error messages from logs
- RAG configuration (embedding endpoint, model)
- File type and size that's stuck
- Nextcloud version
