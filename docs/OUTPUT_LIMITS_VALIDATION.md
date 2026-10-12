# Output-limit compatibility validation

Checked on 2026-10-08 for PR #30, including the follow-up fixes for reordered
truncated legacy JSON and unrelated saved settings blocking the admin form.

## Runtime matrix

| Nextcloud | PHP | Talk | Database |
| --- | --- | --- | --- |
| 30.0.17 | 8.3.28 | 20.1.11 | SQLite |
| 33.0.6 | 8.4.22 | 23.0.11 | SQLite |
| 34.0.3 | 8.5.10 | 24.0.5 | MariaDB 10.11.16 |
| 35.0.1 | 8.5.11 | 25.0.5 | MariaDB 10.11.16 |

Each runtime passes the full **738-test / 5,804-assertion PHP unit suite**.
The PHP 8.5 runs report 16 pre-existing deprecations, with no failing tests.
Unit tests use their normal test bootstrap; the real Nextcloud checks below
exercise the installed core, SQL database, dependency injection and HTTP stack.

On all four installations, upgrading **Talk AI 2.42.2 to 2.42.3** adds both output
settings, defaults to 32768, preserves existing settings, resolves BotService and
LLMClient through real dependency injection, and roundtrips custom global/model
limits through SQL. Separate **fresh app installations on NC30 and NC35** pass
the same schema/default/service checks. These are app-install/upgrade checks on
each core version, not a Nextcloud-major-version upgrade test.

## Real HTTP and browser checks

**35 HTTP checks per runtime, 140 total**, use the real Nextcloud HTTP client and
a loopback controlled provider. They cover sync/stream and classic/reasoning
parameters, 32768 defaults, discovered output caps, the original fitting-history
regression, HTTP 400 context errors without retry, route-specific fallback
budgets, partial/empty/native-tool/legacy-tool endings, and real BotService
history persistence. SSE is sent in chunks that split JSON and UTF-8 sequences.
The existing stream-to-sync retry on a primary HTTP 503 is distinguished from
the subsequent secondary-provider fallback; each keeps its own correct budget.

**Nine Talk browser scenarios per runtime, 36 total**, pass via the actual
composer, registered bot webhook and persisted messages:

1. Complete reply at the 32768 default.
2. Partial reply, incomplete notice/status and saved assistant history.
3. Empty output-limit notice without invented assistant history.
4. Truncated native tool turn: no tool execution or published preamble.
5. Retained 15,990-character history with a discovered 16k context and 4096 output.
6. Discovered 2048 output cap.
7. Exact secondary reasoning-model override of 65536.
8. Long Unicode partial split at Talk's 32000-character boundary.
9. German incomplete notice and persistence.

The admin browser checks save/reload budgets while preserving temperature **0.37**
and history limit **7311**. The previous compiled admin module rejects both as
HTML step mismatches; the corrected inputs accept them without weakening their
range validation. Activity filtering and downloaded trace JSON confirm incomplete
status and numeric budget/usage fields. Frontend tests (21), ESLint, Stylelint,
PHP syntax and the production build also pass.

### Separate Talk 23.0.11 warning

NC30, NC34 and NC35 have no JavaScript runtime exceptions in these flows.
All nine functional NC33 scenarios pass, but Talk 23.0.11 throws
`SessionStorage is not defined` in its `talk-main.js` unload handler when leaving
a conversation. The same exception is reproduced with **Talk AI disabled** while
navigating from Talk to Dashboard. This upstream warning is not fixed by PR #30;
the raw browser report retains the failed console-cleanliness assertion.

## Additional regressions and limits

The normalizer adds 23 permanent cases: ten reordered/truncated legacy envelopes,
ten negative controls and three complete counterparts. Sync and streaming paths
remain covered; no reconstructed tool call is executed. An independent 32-case
probe also covers explicit budgets 1/128/4096/65536, multilingual text/tool history
and different endpoint caps for the same model name (768 assertions).

Providers in this matrix are controlled fixtures, not live model inference.
Reported token usage is synthetic; these tests prove transport and application
behavior, not tokenizer accuracy, reasoning quality or provider capacity. The
Unicode-character estimate and one-token floor remain heuristic. Legacy-envelope
recognition is deliberately narrow, not a general malformed-JSON parser.
NC31/32, PostgreSQL, a live 16k provider, and concurrent production load are not
covered by this run. No merge, release or production deployment is included.
