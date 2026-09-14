# One package for Talk AI and EDUC AI

Version 2.41.0 uses the same `educai` package and release pipeline for all installations.
The package metadata and App Store listing remain **Talk AI**. There is no private
overlay or second release channel to apply after updates.

## Administration

The **Embeddings / Knowledge sources** section is open by default in administration:

- Configure RAG, the embedding provider and chunking there.
- **Reindex All Embeddings** queues all bot sources when RAG is enabled, plus the
  Catalogue only when that integration is enabled. The scope is based on saved settings.
- **Reindex catalogue only** leaves bot sources untouched.
- Save changed settings first. Model, effective endpoint, credential and chunking
  changes leave persistent reminders; Catalogue endpoint changes affect Catalogue only.
- Status separates queued, processing, ready and failed sources. Queuing is not completion.
  Nextcloud cron must process background jobs. Duplicate requests reuse queued work.
- Failed source IDs are shown without exposing raw provider responses or URLs. The
  relevant bot source settings and Nextcloud log provide diagnostic context.

**Course catalogue integration** contains only integration settings: enablement, the
API endpoint, refresh interval and an explicit connection test. It is disabled on new
installations. Disabled providers offer no Catalogue tools and queued/periodic jobs
make no Catalogue requests. Saved configuration, tool assignments and index rows remain.
The connection-test button explicitly contacts the entered endpoint even before enabling
or saving the integration. Enabling Catalogue does not enable RAG or change branding.

## Branding and upgrade

See [admin branding](ADMIN_BRANDING.md) for `occ educai:branding:set --name="EDUC AI"`,
`educai:branding:show` and `educai:branding:reset`. Display name and wiki storage root are
independent persistent configuration. Changing branding never renames user bots or files.

Normal Nextcloud upgrades apply the migration. Existing Catalogue settings, index tables,
job class names and tool identifiers are retained; legacy `catalogue_search_courses`
assignments resolve to the current tool without a background database rewrite.

The known internal **2.40.0.1** distribution migrates with **EDUC AI** as display name and
wiki root. Public and new installations default to **Talk AI**. For older custom builds
without an identifiable version marker, explicitly configure the display name through
OCC; do not infer it solely from a folder name. Existing per-bot folders under either
legacy wiki root remain accessible. When both exist, the pinned configured root wins.
No automatic folder moves, integration resets, index deletion or account recreation occur.

Before a production upgrade, back up the database and user files, verify the previous
distribution and test the normal upgrade in your staging environment. The application
version is deliberately newer than both 2.40.0 and 2.40.0.1. Deploy one tested artifact to
your image-building environment; do not reapply the old Catalogue/branding overlay.

## Validation and publication

`validate.yml` checks PHP (including SQLite tests), frontend tests, lint and a production
build on pull requests and main. The release workflow also validates the selected tag
before building, signing and publishing its single archive. An EDUC-branded installation
consumes that same artifact and retains its settings across updates.

The release builder stages on the source filesystem, including when invoked from a Git
worktree, and excludes Git metadata/development files from the runtime archive. Nothing
is published merely by changing the app version or opening a pull request.
