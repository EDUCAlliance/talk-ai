# RAG Background Jobs

How RAG indexing works and what to check when documents are stuck in **PENDING**.

## How Indexing Works

When you attach a file or folder to a bot:

1. `RagController::store` creates a `BotSource` record with status `pending` and queues a `ReindexBotSourceJob`.
2. Nextcloud cron picks up the job, and `RagIngestionService` resolves the file(s), extracts text, chunks it, requests embeddings, and stores the vectors.
3. The source status changes to `ready`, or `error` with a message shown in the bot edit page.

A `CleanupOrphanedSourcesJob` also checks source reachability every 6 hours. Missing or inaccessible sources are excluded from answers; a temporary storage error does not delete their previous index.

Each successful embedding HTTP batch is checkpointed before the next request. A provider quota pause resumes the unfinished generation after `Retry-After`, without repeating its completed batches. Only a complete generation replaces the published index; repeated pauses without progress eventually produce an error.

**Cron is required.** With `backgroundjobs_mode = cron` (the recommended Nextcloud setup), jobs only run when `cron.php` executes — typically every 5 minutes via system crontab or a systemd timer. See the [Nextcloud background jobs documentation](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html) for setup. Without cron, sources stay `pending` forever.

```bash
# check mode
sudo -u www-data php occ config:app:get core backgroundjobs_mode

# run one cron cycle manually
sudo -u www-data php cron.php
```

## Automatic Updates for File and Folder Sources

Changes made through Nextcloud's file APIs queue a `SyncRagSourceJob`: editing a file, creating or copying a child, deleting content, and renaming or moving files or folders. Matching uses node IDs, including the original owner's hierarchy and configured folder-source owners' share-mount views. An owner editing a shared file therefore also refreshes a recipient's knowledge folder containing that share. Delete/rename pre-events capture the old mount ancestry before it disappears. Whole-directory operations also inspect mount roots inside configured knowledge folders, so deleting an unshared parent refreshes a recipient folder containing an individually shared child; no recursive file scan runs during the write event. Indexing still resolves and reads the source with the **knowledge-source owner's permissions**, not the user who triggered the event.

- Writes only schedule work; conversion and embedding run in the background. Bursts are coalesced into a delayed per-source job (eligible after 5 seconds). The actual delay depends on the next cron/worker run; this is not an immediate-search guarantee.
- If indexing or a provider retry is already pending, the change keeps a follow-up job. It checks again after at least 30 seconds without cancelling the provider's `Retry-After` pause. A change made during indexing is therefore processed after that run.
- Renames and moves update indexed paths/citations even when the text is unchanged. An unchanged follow-up does not request new embeddings. Operating-system metadata files such as `._*`, `.DS_Store` and `__MACOSX` do not trigger updates by themselves. Moving a folder into or out of a metadata path refreshes its directly attached descendant sources as well.
- If the last content file is deleted, or every supported document becomes empty, the old vectors are removed atomically. The source still explains that it has no readable files/text. Unsupported-only input, failed conversion, or temporary storage errors preserve the previous complete index; an inaccessible source stays excluded from answers.
- The listener does not write wiki or source files, so indexing does not trigger a file-write loop. File events do not poll URL sources. Changes made directly on storage without Nextcloud file events still need manual reindexing after Nextcloud has detected the changed files.

Scheduling and indexing use Nextcloud's locking provider. Keep `filelocking.enabled` enabled (the default) so simultaneous background workers cannot publish competing generations. Under prolonged scheduling-lock contention an extra checksum-checked follow-up is preferred over losing a file change.

## File Access and Existing Indexes

Both retrieval paths check each indexed file's current identity, readability and membership in the source owner's folder view. Revoking a shared child therefore excludes its cached text even if the enclosing source folder remains reachable and no file-change job was queued. A different file created at the old path does not authorize the previous file's text. These checks do not erase stored chat history or replace background content synchronization.

Older folder indexes have paths but no per-file identities. On retrieval, their unverifiable chunks are withheld and a background refresh is queued; searches may temporarily report unavailable documents until cron completes the refresh. Keep background jobs running, or use the source's **Reindex** action. Existing direct-file sources already carry a file identity, and URL sources do not require this filesystem migration. This refresh may incur normal embedding-provider usage; no database-schema migration is needed.

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
| Source missing or inaccessible | The owner cannot reach the source; it is excluded from answers. Restore access or remove it from the bot |
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
