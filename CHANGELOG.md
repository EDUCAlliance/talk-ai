# Changelog

All notable changes to Talk AI (`educai`) are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). See [docs/RELEASING.md](docs/RELEASING.md) for how entries reach the Nextcloud App Store.

## [Unreleased]

### Added

- The app page is the one place for bots, with its own navigation: Discover, My bots, To review (for people with approval rights), Activity and a short guide. Every view has a link of its own.
- New bot: a template and five steps (basics, who uses it, knowledge, capabilities, check and finish) with a preview in a sidebar. Sources chosen on the way are attached when the bot is created. A bot that needs approval can be created and submitted in one go.
- Bot page with an overview, the history of creation and approval, and the settings in tabs. A save bar collects the changes and says when they go into review first.
- Reviews show what a change to an approved bot alters, the reason, the instruction and the knowledge sources. Feedback is written next to the decision.
- Bots show who can use them by one colour per audience (only you, group, team, everyone), always together with an icon and the words. Group and team admins get "Edit" on bots of their audience.
- A tour behind "How Talk AI works": it walks through the real pages, highlights one element per step and explains it, in the words for what this user has (no step for "To review" without approval rights). Enter and the arrow keys move, Escape ends it; on small screens the explanation sits at the bottom. The short guide stays reachable from the tour.
- Docling Serve: administrators choose how documents are read: the unchanged request, the standard pipeline, or the vision model of a preset that is configured on the Docling server (`pipeline=vlm` with `vlm_pipeline_preset`). Model, address and key stay on the Docling server. Connection test and detection use the same choice, a result for one choice does not count for another, and a refused preset is reported without converting the document another way.
- Personal assistant: Discover and My bots invite every user to set up one bot of their own, whatever other personal bots they have. Once it exists, the invitation disappears and the assistant is pinned in first place. It is an ordinary personal bot with a mention name of its own (`@assistant-<user>`); its folders are added on its page.

### Changed

- The personal settings point to the app page. Creating, editing and reviewing bots, and the activity, moved there.
- Capabilities are switches named after what they do (documents in chat, images, voice messages); the exact tool list stays under "Advanced". The knowledge search turns on with the first source and can be paused there.
- Submitting a bot for approval asks one question instead of four.
- API: `GET /api/v1/bots`, `/public-bots` and `/approvals` return additional fields (`can_edit`, `tools`, source counts, `live_tools`, `is_assistant`). Whoever may save a bot may now also load it, its tools and its sources. `POST /api/v1/bots` accepts `assistant: true` to create the personal assistant of the user and answers 409 (`assistant_exists`) when there already is one. The settings carry `docling_pipeline` and `docling_vlm_preset`; they are stored as app configuration, without a database migration.

## 2.45.0 – 2026-11-02

**Requires Nextcloud 32–35.** Installations on Nextcloud 30 or 31 stay on 2.43.2. The upgrade adds two database migrations (course link settings and the API connection tables); nothing is approved, assigned or reindexed by it.

### Added

- OpenAPI connections: administrators import an OpenAPI 3.0 document and offer individually reviewed, read-only operations as bot tools (Administration → Talk AI → Capabilities → API connections). An import only creates a draft, every operation needs an explicit read-only confirmation, approving and enabling are separate steps, and every call is checked again before a request is sent.
- EDUC course catalogue preset, usable as the record source of the catalogue tools with an explicit switch and rollback.
- Course link template and accepted origins with a live preview. Answers only contain verified course links.
- Index maintenance names each failed knowledge source with its bot, owner and cause, and shows administrators the technical detail with credentials masked.

### Changed

- Frontend migrated to Vue 3 and Nextcloud Vue 9.
- While a tool runs, Talk shows its label instead of the internal tool name.

### Removed

- Support for Nextcloud 30 and 31.

### Security

- HTTP responses are no longer decoded automatically (mitigation for CVE-2026-111497). Talk AI requests uncompressed responses and refuses compressed ones, so LLM providers and proxies must honour `Accept-Encoding: identity`.
- Updated vulnerable frontend dependencies (DOMPurify 3.4.17, Axios 1.21.0, Vite 7.3.7 and others) and hardened admin icon preview URLs.

## 2.43.2 – 2026-10-26

### Fixed

- The Talk webhook URL is generated independently of the route cache, so activating the app no longer registers the instance homepage instead of the webhook endpoint. Invalid or homepage webhook URLs are rejected before registration.
- The upgrade repairs recognised, managed registrations affected by this bug and keeps the bot ID, webhook secret, room assignments and enabled state.

## 2.43.1 – 2026-10-25

Keep Nextcloud background jobs running: older folder indexes without per-file identities are withheld until an automatic refresh completes.

### Changed

- File and folder knowledge refreshes automatically after changes made through Nextcloud.
- Indexing resumes after embedding-provider quota pauses without losing completed batches. The last complete index is kept on temporary failures, and replacement indexes are published atomically.
- Knowledge search returns clearly labelled lower-confidence candidates when no result meets the preferred similarity threshold.
- Wiki and Collectives tools handle page creation, conflicts, subpage hierarchy, links and paginated listings more reliably and verify their writes.
- Model tests distinguish access, quota, timeout, invalid-response and empty-answer failures. Improved GPT-6 parameter compatibility.

### Fixed

- Inaccessible files are excluded immediately after a share is revoked, including shared children of an otherwise accessible folder.
- Room attachment indexes are repaired on retry and after model or chunk-setting changes.
- Room creation with session cookies on port-based URLs. Bots are no longer activated from email addresses, URLs or paths.
- Cancelling bot forms, onboarding and sharing dialogs. Wiki selections survive delayed, out-of-order or failed requests.

## 2.43.0 – 2026-10-14

Review the new response ceiling for your models and set lower per-model overrides where needed.

### Added

- Redesigned administration with Overview, Models, Knowledge, Capabilities, System and Bots tabs, a setup checklist and a save/discard bar.
- Explicit connection checks for model responses, document conversion, image understanding, speech to text and the course catalogue.
- Configurable response length: a 32,768-token budget per model response with optional per-model overrides.
- Assisted Docling setup with "Detect and test".

### Changed

- Primary and secondary model lists refresh independently and keep the last successful list on failure. Administrators search and approve models and can add unlisted IDs.
- Model selections stay bound to their provider. "Use administrator default" restores real inheritance.
- Replies that reach the provider's output limit are delivered with a notice instead of a generic failure.
- Provider addresses ending in `/v1` work directly for chat and image understanding.
- Improved contrast in light and dark themes.

## 2.42.2 – 2026-10-07

### Added

- Nextcloud 35 support (Nextcloud 30–35).
- Docling Serve API support with API profile and authentication settings, and "Test PDF conversion".
- Complete German translation of the administration and bot settings.

### Changed

- Talk webhook requests are limited to 1 MiB and rejected early when their signature or payload is invalid.
- When a request exceeds the model's context window, the bot explains this in Talk. Replies longer than Talk's message limit are split into several messages.

### Fixed

- Fresh installations failed with `Column "oc_educai_wiki_roots"."active" is type Bool and also NotNull`.
- Reading a wiki page from an offset returns the correct section, and upgraded installations no longer report a schema mismatch for the wiki tables.

## 2.41.0 – 2026-09-16

### Added

- Optional course catalogue integration and installation branding in the public `educai` package. The catalogue is disabled by default on new installations.
- Index maintenance in the administration.

### Changed

- Catalogue indexing keeps the previous records when a fetch is incomplete or an embedding batch fails.

## 2.40.0 – 2026-08-28

### Added

- Localized frontend and API responses.

### Changed

- Reworked agent execution and hardened queued delivery.

### Fixed

- Requests are retried when a provider rejects `temperature` or `max_tokens`, including fallback models with reasoning parameters.
- Empty user or assistant turns, orphaned tool results and empty tool property arrays are no longer sent, and provider 4xx errors are no longer silent.

## 2.39.5 – 2026-07-21

### Changed

- Compact EU co-funding logo in the App Store description.

## 2.39.4 – 2026-07-21

### Added

- "Co-funded by the European Union" logo and funding disclaimer in the App Store description.

## 2.39.3 – 2026-07-19

### Fixed

- Requests to reasoning models.

## 2.39.2 – 2026-07-14

### Changed

- Contact address changed to nextcloud.apps@educalliance.eu.

## 2.39.1 – 2026-07-13

First public release on the Nextcloud App Store: multiple purpose-built AI bots in Nextcloud Talk, each with its own prompt, model, knowledge and tools; personal, group, team and global visibility with an approval workflow; any OpenAI-compatible endpoint; knowledge sources with optional Docling conversion; document search, image analysis, audio transcription, Markdown wikis and MCP tools; and native Talk integration with Smart Picker support.
