# Unified package validation — 2026-09-14

Local validation of the proposed 2.41.0 package, based on public main
`445cd822b8ee6f1c99cf8221d7a023969f6b6071`. No production server or App Store release was changed.

## Automated checks

- PHP 8.4.22 / SQLite in Nextcloud Docker: **459 tests, 3,055 assertions**, no failures,
  warnings or skipped tests. Repeated with the exact CI Composer dependency installation.
- Frontend: **11 tests passed**; ESLint, Stylelint and PHP syntax checks passed.
- Production build and clean release-archive build passed with Node 22.
- Archive includes one `educai` app, Catalogue and branding commands. No application
  development directories or source maps; all **220 non-vendor runtime files** match
  the validated working tree byte-for-byte.

## Real Nextcloud checks

Nextcloud 33.0.6, Talk 23.0.7, PHP 8.4.22, SQLite; disposable copies on an internal
Docker network. The pre-existing stopped Nextcloud containers and volumes were not modified.

- Public 2.39.1 → 2.41.0 upgrade: successful; display/wiki defaults remain Talk AI.
- Actual internal 2.40.0.1 → 2.41.0 upgrade: successful; EDUC AI display and wiki root,
  enabled Catalogue, endpoint, interval, seeded index vector and wiki file preserved.
- Fresh Nextcloud / fresh app install without Talk: all migrations passed; Talk AI
  branding, Talk AI wiki root and disabled Catalogue with no Catalogue tools confirmed.
  OCC set/reset succeeds gracefully when Talk is absent.
- OCC branding on the Talk-enabled instance changes navigation/settings/initial state
  to EDUC AI while leaving the wiki root at Talk AI and updating the existing Talk
  registration in place.
- **27 backend integration checks** passed using synthetic loopback-only Catalogue and
  embedding services: real Files/RAG indexing, async job status, disabled no-I/O behavior,
  current/archive indexing, semantic search, legacy aliases, partial-fetch preservation,
  recovery, queue coalescing, config-change handling and wiki/assignment preservation.
- **6 browser checks** passed: visible standalone maintenance, save-before-reindex,
  edits made while a save response is delayed remain dirty, reminder survives reload,
  Catalogue-only/repeated queue actions, anonymous denial and no JavaScript runtime errors.

Reusable harnesses and execution boundaries are documented in
[tests/integration](../tests/integration/README.md). Browser tests require a protected
synthetic-user storage state and deliberately leave fixture settings in their disposable
instance. No production provider calls were used.

## Scope of comparison

The old private admin UI and associated Settings/Tools APIs and indexing jobs were
compared with the public implementation. The lost generic Reindex All control and its
orphaned Catalogue callback are replaced by independent maintenance; the useful
Catalogue-only action is retained there. Model, queue, media, memory and generic tool
administration remain available. This is a focused integration comparison, not a claim
of a complete audit of every historical fork change.

New PR/release CI validates PHP 8.1 using SQLite and the pinned Composer lockfiles.
The real Nextcloud smoke described above was run locally on PHP 8.4.22; other database
engines and a live production rollout are outside these results.
