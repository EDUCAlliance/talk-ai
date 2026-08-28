# Development

Build, test, and local verification. Requirements: Nextcloud 30–34, PHP 8.1+, Node.js 22, npm 10.5+. The package scripts expect the app inside a Nextcloud checkout, usually under `apps-extra/educai`.

## Frontend

```bash
npm ci                 # install dependencies
npm run build          # production build (writes bundles to js/)
npm run watch          # rebuild on change
npm run lint           # eslint
npm run stylelint
npm run test:frontend
```

Commit the generated `js/` assets — the repository ships deployable bundles. After frontend changes, run a build and hard-refresh the browser (Nextcloud caches bundles aggressively).

## Translations

Use Nextcloud's localization helpers for every user-facing string:

- JavaScript/Vue: import `t` or `n` from `src/l10n.js` and call them directly so the extractor can discover the source string.
- PHP: inject `OCP\IL10N` and use `t()` or `n()` at the HTTP boundary. Return a stable `errorCode` alongside a safe localized message; keep exception details in logs and traces.
- Do not translate technical identifiers, API codes, routes, model IDs, persisted paths, or user-provided content.

Generate catalogs with Nextcloud's official `translationtool.phar` from the app root:

```bash
php /path/to/translationtool.phar create-pot-files
# update translationfiles/de/educai.po
msgfmt --check --check-format -o /dev/null translationfiles/de/educai.po
php /path/to/translationtool.phar convert-po-files
```

Commit the source catalog under `translationfiles/` and the generated runtime catalogs under `l10n/`. Nextcloud selects the catalog from the signed-in user's language automatically; no app-specific language setting is needed.

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
