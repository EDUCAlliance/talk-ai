# Development

Build, test, and local verification. Requirements: Nextcloud 30–34, PHP 8.1+, Node.js 22, npm 10.5+. The package scripts expect the app inside a Nextcloud checkout, usually under `apps-extra/educai`.

## Frontend

```bash
npm ci                 # install dependencies
npm run build          # production build (writes bundles to js/)
npm run watch          # rebuild on change
npm run lint           # eslint
npm run stylelint
```

Commit the generated `js/` assets — the repository ships deployable bundles. After frontend changes, run a build and hard-refresh the browser (Nextcloud caches bundles aggressively).

## PHP

```bash
# unit tests (lightweight bootstrap — no server install needed)
vendor/bin/phpunit --bootstrap tests/unit/bootstrap.php tests/unit

# single test file
vendor/bin/phpunit --bootstrap tests/unit/bootstrap.php tests/unit/Service/SettingsServiceTest.php

# syntax check
php -l lib/Service/BotService.php
```

## Migrations

Migrations live in `lib/Migration/` and run on app enable and `occ upgrade`:

```bash
sudo -u www-data php occ app:enable educai
sudo -u www-data php occ upgrade --no-interaction
sudo -u www-data php occ migrations:status educai
```

## Useful occ Commands

Adapt the prefix to your setup (e.g. `docker exec -u www-data <container> php occ …`):

```bash
sudo -u www-data php occ app:enable educai        # also re-runs repair steps
sudo -u www-data php occ app:disable educai
sudo -u www-data php occ talk:bot:list             # shared Talk bot registration
sudo -u www-data php cron.php                      # run background jobs (RAG etc.)
sudo -u www-data php occ background-job:list --limit=200
sudo -u www-data php occ background-job:execute --force-execute <job-id>
```

When testing Talk behavior, watch `nextcloud.log` for `EducAI:` entries.

## Documentation

Keep the root README short; details belong in `docs/`. When changing behavior, update the closest guide (see [docs/README.md](README.md) for the index).
