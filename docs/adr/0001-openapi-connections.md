# ADR 0001: Data model for OpenAPI connections

- Status: accepted
- Date: 2026-10-30
- Migration: `Version024500Date20261030010000` (additive only)

## Context

Admins register external read-only HTTP APIs from an OpenAPI 3.0 JSON document
and expose individually reviewed `GET` operations as bot tools. Bot assignments
already live in the name-based `BotTool` loadout, so a tool name is a durable
grant. It must therefore never be derived from text that the spec author
controls (`operationId`, summary, description), must survive spec updates and
must never be handed to a different operation.

## Decision

Three tables, each owning exactly one kind of truth:

| Table | Owns | Mutable |
|---|---|---|
| `educai_api_conn` | Connection settings: name, approved base URL, auth mode and header, encrypted credential, declared data visibility, enabled flag, pointer to the active revision | yes |
| `educai_api_rev` | One imported document: original bytes, SHA-256, OAS version, compiled manifest (operations, diagnostics, conflicts), per-operation review config, status `draft` → `approved` → `superseded`, approval snapshot | only while `draft`, then frozen except for revocations |
| `educai_api_op` | Stable operation identity: `match_key` = `METHOD` + exact path template, `active_key`, `last_label`, tombstone | identity never changes |

The tool name is `api_<connection id>_<operation identity id>` (at most 25
ASCII characters, `/^api_[1-9][0-9]{0,9}_[1-9][0-9]{0,9}$/`). Labels and
descriptions shown to users and models come from the admin review, not from
the spec.

### Identity

- The match key is the upper-case method plus the path template exactly as
  written in the spec. There is no case folding, percent decoding, slash
  trimming or parameter renaming; `/a/{x}` and `/a/{y}` are different operations.
- `operationId` is stored as a hint only. Duplicate operationIds are reported
  as conflicts and block the affected operations instead of being remapped.
- `active_key` = `sha256(<connection id> "\n" <match key>)` is set while the
  identity is live and backed by a unique index. A re-import resolves existing
  identities through it, so label or description changes keep the tool name.
- When an approved revision no longer contains an identity, `active_key` is
  cleared and `tombstoned_at` is set. A later import of the same method and
  path gets a **new** ID, so old bot grants are never reactivated or
  transferred. Re-granting is an explicit admin action.
- A draft that still references an identity tombstoned after it was imported
  cannot be approved (`revision_outdated`); it has to be re-imported.
- There is no admin remap in this version.

### Revisions and approval

- Import always creates a draft. It never enables a connection and never adds
  bot capabilities. Importing again discards older drafts; re-importing the
  active document is rejected (`document_unchanged`).
- Review config is copied from the active revision only for operations whose
  compiled definition fingerprint is unchanged and which were not revoked.
  Any semantic change requires a new review.
- Approval runs in one transaction: the previous revision becomes
  `superseded`, the draft becomes `approved` with a snapshot of the base URL and
  the auth mode/header it was reviewed against, the connection points to it,
  and identities missing from it are tombstoned. Until that commit the previous
  revision stays active.
- Changing the base URL or auth mode/header later suspends every tool of the
  connection until a revision is approved again. Replacing or removing the
  secret value does not need re-approval; a missing secret is a runtime error.
- Revoking an operation in the approved revision withdraws it immediately and
  is recorded with actor and time.

### Avoiding duplicate truths

Per-revision definitions (parameters, input schema, security alternatives,
response mapping suggestion) exist only inside the revision manifest. The
operation table holds identity and the last approved label only; the label is
cached so that already assigned tools can be shown as "unavailable" with a
readable name after a connection is disabled. Runtime always reads the
connection, its active revision and the identity fresh before executing.

### Credentials and bot overrides

The credential is encrypted with `CredentialService` and never returned by the
API. Base URL, auth, credential and method are never taken from the per-bot
`config_override`; OpenAPI tools ignore that field completely, so fixed query
values are the only server-side argument defaults and they are part of the
reviewed revision.

## Consequences

- Tool names are stable across spec updates as long as method and path stay
  the same, and grants cannot leak to other operations.
- Renaming a path in the spec retires the old tool and creates a new one that
  has to be assigned again. This is intentional.
- Documents up to 2 MiB are stored per revision. Superseded revisions are kept
  for audit and diff; deleting a connection deletes all of its rows.
- A concurrent first import of the same operation can hit the unique index.
  The importer retries the lookup once; on PostgreSQL the surrounding
  transaction is aborted by the violation, so the import fails with an error
  and can simply be repeated.
