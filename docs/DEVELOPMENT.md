# Talk AI Development

This guide covers build, test, and local verification work for the app.

## Requirements

- Nextcloud 30 to 33
- PHP 8.1 or newer
- Node.js 22 or newer
- npm 10.5 or newer
- Composer dependencies from the surrounding Nextcloud workspace

The package scripts expect the app to live inside a Nextcloud checkout, usually under `apps-extra/educai`.

## Frontend Build

Install dependencies once:

```bash
npm install
```

Build production assets:

```bash
npm run build
```

Build during development:

```bash
npm run watch
```

Lint frontend code:

```bash
npm run lint
npm run stylelint
```

Generated files are written under `js/`. Commit generated assets when the repository expects deployable app bundles.

## PHP Tests

Run the unit suite with the lightweight bootstrap:

```bash
vendor/bin/phpunit --bootstrap tests/unit/bootstrap.php tests/unit
```

Run a targeted test file:

```bash
vendor/bin/phpunit --bootstrap tests/unit/bootstrap.php tests/unit/Service/SettingsServiceTest.php
```

Some full Nextcloud PHPUnit bootstraps require an initialized server install and may fail in a plain workspace. Prefer the app unit bootstrap unless the test needs the full server.

## PHP Lint

Use `php -l` for changed PHP files:

```bash
php -l lib/Service/BotService.php
php -l lib/Service/LLMClient.php
```

## Migrations

Migrations run during app enable and Nextcloud upgrade:

```bash
sudo -u www-data php occ app:enable educai
sudo -u www-data php occ upgrade --no-interaction
sudo -u www-data php occ migrations:status educai
```

Current migration files live in `lib/Migration/`. Recent schema work includes per-bot temperature, Talk attachment and room-document support, admin layout changes, and secondary endpoint fallback settings.

## Local Docker Verification

The commands below assume a standard Nextcloud installation; adapt the prefix to your dev setup (e.g. `docker exec -u www-data <container> php occ …` for Docker-based environments).

Useful commands:

```bash
sudo -u www-data php occ status
sudo -u www-data php occ app:list
sudo -u www-data php occ migrations:status educai
sudo -u www-data php occ app:disable educai
sudo -u www-data php occ app:enable educai
```

Run cron manually when testing RAG or queued work:

```bash
sudo -u www-data php cron.php
```

List or execute queued jobs directly when testing one-off background work:

```bash
sudo -u www-data php occ background-job:list --limit=200
sudo -u www-data php occ background-job:execute --force-execute <job-id>
```

For direct database inspection, use your database client of choice against the Nextcloud database.

## Runtime Checks

After changing admin settings, bot forms, or Smart Picker code:

1. Run `npm run build`.
2. Reload or re-enable the app if the server-side wiring changed.
3. Open a fresh browser tab to avoid stale Nextcloud bundles.
4. Verify the affected page in the local Nextcloud UI.

After changing migrations or schema-dependent services:

1. Run targeted PHP tests.
2. Run `occ upgrade`.
3. Check `occ migrations:status educai`.
4. Inspect the affected table or UI state.

After changing wiki index sync:

1. Run `occ upgrade` or disable/enable the app so the registry tables and backfill job are applied.
2. Execute the queued `OCA\EducAI\Jobs\RebuildWikiRootRegistryJob` or run cron.
3. Inspect `educai_wiki_roots` and `educai_wiki_root_bots` to confirm existing wiki bots were registered.
4. Create or delete a Markdown file through WebDAV in a Collectives wiki and in a normal personal-files wiki.
5. Confirm a deduplicated `OCA\EducAI\Jobs\SyncWikiRootIndexJob` appears for the expected `root_id`.
6. Execute the job and check that the wiki root `index.md` `Existing Files` section changed.

After changing Talk behavior:

1. Confirm the shared Talk bot registration.
2. Test the target room path.
3. Check `nextcloud.log` for `EducAI:` entries.

## Documentation Rules

Keep the root README short. Put setup details, runtime behavior, and analysis documents under `docs/`.

When changing behavior, update the nearest specific guide:

- RAG behavior: `docs/RAG_TOOL_GUIDE.md` or `docs/RAG_BACKGROUND_JOBS_GUIDE.md`
- MCP behavior: `docs/MCP_TOOL_CALLING_ANALYSIS.md`
- local operations: `docs/docker_commands_cheatsheet.md`
- product overview: `docs/FEATURES.md`
- architecture: `docs/ARCHITECTURE.md`
