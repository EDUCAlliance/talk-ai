# Disposable Nextcloud integration smoke

`nextcloud-smoke.php` exercises real Nextcloud DI, app services, SQL mappers,
native Files, job queue registration, and Catalogue/RAG workers. The accompanying
loopback fixture replaces every embedding/Catalogue request; no real provider or
user credentials are needed. Controller authorization and rendered UI still need
separate HTTP/browser checks.

Use a **disposable Nextcloud Docker instance with Talk AI already installed and
an empty Catalogue index**. The smoke runner deliberately changes its settings
and creates a synthetic user, bot, source, and tool assignment. Its `finally`
block removes the test records and restores changed settings. Never use it on an
existing working installation.

Example (adjust only the disposable container name):

```sh
docker cp tests/integration talk-ai-unified-test:/tmp/educai-integration
docker exec --user www-data -d talk-ai-unified-test php -S 127.0.0.1:18095 /tmp/educai-integration/fixture-router.php
docker exec --user www-data -e EDUCAI_TEST_ALLOW_MUTATION=1 talk-ai-unified-test php /tmp/educai-integration/nextcloud-smoke.php
```

The test enables local HTTP requests only for its duration. The default fixture
origin is `http://127.0.0.1:18095`; `EDUCAI_TEST_FIXTURE_ORIGIN` may override the
loopback port, but remote hosts are rejected. Success prints only check names,
not settings, passwords, request headers, or provider payloads.

## Signed webhook and large-context regression

`webhook-context-smoke.php` sends **real HTTP requests with real Talk HMAC
signatures** through the Nextcloud controller, app services, local synthetic LLM,
and Talk's own bot-message API. It compares the replies actually stored by Talk
with the app's conversation history; no external model or credentials are needed.
It is not a stub test and does not substitute a mock for Talk's message limit.

Use a **disposable Docker Nextcloud with Talk and Talk AI enabled**, a synthetic
user, and a dedicated Talk room owned by that user. Talk AI's registered webhook
bot must already be enabled in that room (`occ talk:bot:list` and
`occ talk:bot:setup <bot-id> <room-token>`). Run serially: no users, cron jobs or
other smokes should write to the room or settings while it is running.

```sh
docker cp tests/integration talk-ai-webhook-nc:/tmp/educai-integration
docker exec --user www-data -e EDUCAI_TEST_ALLOW_MUTATION=1 -d talk-ai-webhook-nc \
  php -S 127.0.0.1:18096 /tmp/educai-integration/context-fixture-router.php
docker exec --user www-data \
  -e EDUCAI_TEST_ALLOW_MUTATION=1 \
  -e EDUCAI_TEST_USER=synthetic-user \
  -e EDUCAI_TEST_ROOM=disposable-room-token \
  talk-ai-webhook-nc php /tmp/educai-integration/webhook-context-smoke.php
```

The two environment values are mandatory, not real account credentials. Both the
runner and fixture require the explicit mutation flag and a Docker environment.
`EDUCAI_TEST_FIXTURE_ORIGIN` defaults to `http://127.0.0.1:18096` and
`EDUCAI_TEST_BASE_URL` to `http://127.0.0.1`; only loopback HTTP origins are accepted.
Nextcloud must trust the loopback address and generate a reachable internal URL
for its Talk API. The runner temporarily permits local HTTP requests, creates a
unique synthetic bot and completed onboarding state, and points that bot at the
fixture. Its `finally` block attempts every cleanup independently: changed app
settings/local HTTP policy, synthetic history/bot/queue/traces, and Talk comments
observed during the test. Room-level Talk activity counters and application logs
are not rolled back; discard the entire disposable instance after testing.

Covered contracts:

- Valid signed chat; missing/invalid HMAC; scalar/null/list JSON roots; scalar `object`;
  malformed JSON. Invalid input must neither reach the model nor enter history.
- Body sizes 1 MiB - 1, exactly 1 MiB, and 1 MiB + 1 using signed JSON whitespace
  padding, so transport and model limits are tested independently; also an
  oversized chunked HTTP body without `Content-Length`.
- A 32,000-character Unicode message and equally large quoted parent, with both
  JSON escaping layers, remain below the transport cap.
- A synthetic 65,536-character model-context overflow makes one model call,
  produces a specific context-limit explanation and stores no assistant error.
- Fifty persisted 32,000-character history rows plus one short recent turn
  exercise the history budget. The short turn is forwarded, with exact message
  and content-character counts; all large rows remain in storage but are omitted
  from the model request. This also detects accidentally disabling history.
- Unicode answers of 32,000, 32,001 and 64,005 characters are compared byte-for-byte
  against the concatenated persisted Talk comments and assistant history. Each
  actual Talk message must stay within 32,000 Unicode characters.

The JSON result includes only assertion names, statuses, lengths, counts and
timings, never secrets or raw provider/user payloads. All contract assertions run
before exit, making the same harness useful against the old revision (nonzero
exit and failing cases) and the fixed revision. A setup/runtime error reports
only its exception class to avoid accidentally printing sensitive HTTP headers.

**Limits:** the synthetic provider deliberately measures characters rather than
model-specific tokens; it proves overflow handling, not a particular vendor's
token window. The history budget is still approximate and excludes the system
prompt, tool schemas and later tool results. Ordinary Talk webhooks contain the
current message and optional parent, not the entire conversation. The long-answer
comparison uses non-whitespace Unicode characters: Talk itself trims each chunk's
leading/trailing whitespace, so splitting can discard whitespace at a boundary;
exact formatting preservation is not claimed. Interrupted/ambiguous delivery
retry handling is covered separately by the unit tests, not this smoke.

## Migration compatibility

`migration-compat-smoke.php` loads the installed Nextcloud core and builds
in-memory schemas with its real schema wrapper. It checks credential conversion
from VARCHAR to TEXT, repeat execution, missing tables/columns and preservation
of unrelated columns. It also checks that the wiki registry follows Nextcloud's
nullable-boolean constraint. It does not execute schema changes against the DB.

Run on a disposable Nextcloud 30 and 35 instance with Talk AI installed:

```sh
docker exec --user www-data talk-ai-test \
  php /var/www/html/custom_apps/educai/tests/integration/migration-compat-smoke.php
```

This complements a fresh `occ app:enable educai` test; it does not replace it.

## Browser regression

`browser-smoke.cjs` drives the rendered admin controls and checks the save-in-flight
race, persistent reindex reminder, Catalogue-only queueing, duplicate queueing,
anonymous access and JavaScript errors. It requires Playwright and a normal login's
protected `storageState` file for a synthetic admin. Keep this file outside the repo.

```sh
EDUCAI_TEST_ALLOW_MUTATION=1 \
EDUCAI_TEST_STORAGE_STATE=/protected/test-browser-state.json \
EDUCAI_TEST_BASE_URL=http://127.0.0.1:8096 \
node tests/integration/browser-smoke.cjs
```

`EDUCAI_TEST_CHROME` optionally selects a local browser executable;
`EDUCAI_TEST_PLAYWRIGHT_MODULE` optionally selects an existing Playwright installation.
The browser test leaves synthetic endpoint settings and one queued Catalogue job in
the disposable instance for inspection. Stop/discard that instance after testing;
do not run this against a working installation. No password is embedded in the test.
