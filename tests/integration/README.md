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
