# RAG Background Jobs

How RAG indexing works and what to check when documents are stuck in **PENDING**.

## How Indexing Works

When you attach a file or folder to a bot:

1. `RagController::store` creates a `BotSource` record with status `pending` and queues a `ReindexBotSourceJob`.
2. Nextcloud cron picks up the job, and `RagIngestionService` resolves the file(s), extracts text, chunks it, requests embeddings, and stores the vectors.
3. The source status changes to `ready`, or `error` with a message shown in the bot edit page.

A `CleanupOrphanedSourcesJob` runs every 6 hours and removes embeddings for sources whose files have been deleted.

**Cron is required.** With `backgroundjobs_mode = cron` (the recommended Nextcloud setup), jobs only run when `cron.php` executes — typically every 5 minutes via system crontab or a systemd timer. See the [Nextcloud background jobs documentation](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html) for setup. Without cron, sources stay `pending` forever.

```bash
# check mode
sudo -u www-data php occ config:app:get core backgroundjobs_mode

# run one cron cycle manually
sudo -u www-data php cron.php
```

## Document Conversion (Docling)

Plain-text formats (`txt`, `md`, `csv`, `json`, `xml`) are indexed directly. With **Docling** enabled, Talk AI can also ingest PDF, Word, PowerPoint, Excel, and images (OCR): the file is sent to the configured conversion endpoint, converted to Markdown, then chunked and embedded like any text file.

Enable it under **Administration settings → Talk AI → Document Conversion**: check *Enable Document Conversion*, optionally set a custom endpoint and a dedicated API key. If the Docling key is blank, the main chat API key is used — the effective key must have access to the `/v1/documents/convert` endpoint.

Docling issues:

| Error | Fix |
|---|---|
| "Docling document conversion is disabled" | Enable it in admin settings |
| "API key not configured for Docling" | Set the Docling key or the main API key |
| "Failed to convert document" | Check file type support, endpoint reachability, and the log |
| Large documents time out | Default timeout is 120 s; very large files may need higher PHP limits |

## Troubleshooting Stuck Sources

1. **Verify cron runs** (`crontab -l`, or trigger manually: `sudo -u www-data php cron.php`).
2. **Watch the log** while triggering:
   ```bash
   tail -f /path/to/nextcloud/data/nextcloud.log | grep -i educai
   ```
3. **Check the source's error message** in the bot edit page (shown below the status).
4. **Click "Reindex"** on the source, run cron again, refresh the page.

### Common Error Messages

| Message | Meaning / fix |
|---|---|
| "RAG is disabled by the administrator" | Enable RAG in admin settings |
| "No readable files found for source" | File missing or unreadable — check Nextcloud permissions |
| "File or folder no longer exists" | Source was deleted; embeddings are cleaned up automatically. Remove the source from the bot |
| Embedding API errors (401/403/500) | Check embedding endpoint, key, and model name; test the endpoint with curl |
| "Failed to extract text" | Unsupported type (enable Docling for PDF/Office/images), or file too large for PHP memory |

### Check RAG Configuration

In **Administration settings → Talk AI → RAG & Embeddings**: RAG enabled, endpoints and keys set, model names correct. Or from the CLI:

```bash
sudo -u www-data php occ config:list educai
```

## Limits & Performance

- Jobs run per cron cycle and process one source at a time; large folders take multiple cycles.
- Recommended: ≤ 100 files per bot, ≤ 10 MB per file, PHP memory 512 MB+ for large files.
- Embedding-API rate limits slow down ingestion but don't fail it.

## Direct Database Inspection (debugging only)

```sql
-- pending sources
SELECT * FROM oc_educai_bot_sources WHERE status = 'pending';

-- embeddings per bot
SELECT bot_id, COUNT(*) FROM oc_educai_embeddings GROUP BY bot_id;

-- queued Talk AI jobs
SELECT id, class, last_run, argument FROM oc_jobs
WHERE class LIKE '%EducAI%' ORDER BY id DESC LIMIT 20;
```

## Quick Reference

```bash
sudo -u www-data php occ background-job:list            # queued jobs
sudo -u www-data php occ background-job:worker           # process jobs continuously
sudo -u www-data php cron.php                            # one cron cycle
sudo -u www-data php occ config:app:get core backgroundjobs_mode
sudo -u www-data php occ config:list educai
sudo -u www-data php occ migrations:status educai
```
