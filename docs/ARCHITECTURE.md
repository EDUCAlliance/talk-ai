# Talk AI Architecture

This document maps the current app structure to the main runtime flows.

## Application Stack

Talk AI is a standard Nextcloud app:

- PHP services and controllers use the Nextcloud app framework.
- Vue 2.7 components provide the personal app, admin settings, and Talk Smart Picker UI.
- Database access uses Nextcloud mapper entities.
- Background work runs through Nextcloud jobs.
- External model calls use OpenAI-compatible HTTP APIs.

The app ID is `educai`. The PHP namespace is `OCA\EducAI`.

## Main Backend Components

| Component | Responsibility |
| --- | --- |
| `BotService` | Bot lifecycle, permission-aware bot access, Talk message orchestration, tool loadout selection |
| `LLMClient` | Chat completions, streaming, model listing, endpoint-aware model resolution, fallback retry |
| `AgentExecutor` | Tool-calling loop, tool-call parsing, iteration cap, final answer synthesis |
| `SettingsService` | Admin settings, encrypted secrets, runtime defaults |
| `TalkHandler` | Incoming Talk webhook handling |
| `TalkBotRegistrationService` | Shared Talk bot registration and refresh |
| `BuiltInToolProvider` | RAG, room-document, attachment, and wiki tool definitions and execution |
| `ToolRegistry` | MCP tool lookup and per-bot tool assignment loading |
| `McpClient` | MCP `tools/list` and `tools/call` requests |
| `RagIngestionService` | Source resolution, text extraction, chunking, embedding, and source status updates |
| `WikiService` | Persistent personal bot wiki reads, writes, search, and log maintenance |
| `WikiRootRegistryService` | Registry of physical wiki roots and bot assignments for scalable file-event matching |
| `WikiFileEventSyncService` | Lightweight file-event handler that matches node ancestors and queues wiki index sync jobs |

## Frontend Entry Points

| File | Surface |
| --- | --- |
| `src/views/PersonalBots.vue` | Personal app view, pending approvals, bot lists |
| `src/components/BotForm.vue` | Bot creation and editing |
| `src/components/AdminSettings.vue` | Admin configuration accordions |
| `src/components/BotPickerElement.vue` | Talk Smart Picker entry |
| `src/components/BotPickerModal.vue` | Talk room bot activation UI |

The Vite build writes app bundles into `js/`.

## Data Model

Core tables use the `educai_` prefix in code and the configured Nextcloud database prefix at runtime.

| Table | Purpose |
| --- | --- |
| `educai_bots` | Bot configuration, visibility, model, temperature, approval state |
| `educai_conversations` | Stored user and assistant messages |
| `educai_settings` | Global provider, RAG, tool, rate-limit, and memory settings |
| `educai_bot_sources` | Bot-attached RAG files and folders |
| `educai_embeddings` | RAG chunks and embeddings |
| `educai_tools` | Admin-registered MCP endpoints |
| `educai_bot_tools` | Bot-to-tool assignments |
| `educai_chat_rooms` | Per-room onboarding and response mode |
| `educai_room_document_sources` | Talk-room document sources |
| `educai_room_document_embeddings` | Talk-room document embeddings |
| `educai_wiki_roots` | Physical wiki root folders keyed by stable Nextcloud node ID |
| `educai_wiki_root_bots` | Active mapping from personal bots to registered wiki roots |

## Message Flow

1. Nextcloud Talk sends a signed webhook to `/apps/educai/webhook/talk`.
2. `TalkHandler` parses the message, room, mention, and attachments.
3. `BotService` resolves the mentioned bot and checks visibility plus approval state.
4. The service builds the effective system prompt, onboarding context, attachment hints, RAG instructions, and wiki instructions.
5. `BotService` loads enabled built-in and MCP tools for the bot.
6. If tools are available, `AgentExecutor` runs the model-tool loop. If no tools are available, `LLMClient` sends a direct chat request.
7. The final assistant response is sent back to Talk.
8. Talk AI stores the user message and final assistant message. Tool results stay in memory for the turn.

## Tool Loop

`AgentExecutor` exposes OpenAI-style tool schemas to the model. It supports native tool calls plus fallback parsing for models that emit tool-call JSON or XML in text.

The hard cap is `MAX_TOOL_ITERATIONS = 50`. A request can ask for a smaller cap, but not a larger one. This prevents runaway agent loops while keeping complex workflows possible.

For audio-only Talk messages with exactly one audio attachment and no image or document attachment, `BotService` passes an initial tool choice for `attachment_transcribe_audio`. The executor applies that preference to the first tool step only.

## Model Endpoint Resolution

`LLMClient` resolves model references before each request:

- `primary:<model>` uses the primary endpoint.
- `secondary:<model>` uses the secondary endpoint.
- raw model names use the primary endpoint unless the configured model list resolves them to another endpoint.

Model listing combines primary and secondary `/v1/models` results when the secondary endpoint is configured.

Fallback retry uses the configured fallback model only for eligible timeout or connection failures. Streaming fallback runs only before the first streamed chunk.

## RAG And Embeddings

RAG source ingestion runs through background jobs. The ingestion service resolves files through Nextcloud storage APIs, extracts text, chunks content, requests embeddings, and stores vectors in JSON-compatible form.

The built-in `rag_search_documents` tool embeds the model-generated query and computes cosine similarity in PHP. This keeps the implementation database-portable.

## Wiki Index Sync

Wiki tools keep root-level `index.md` pages up to date through `WikiService::syncIndexForRoot()`. The same index sync also runs after manual file changes, but the hot file-event path stays small enough for large instances:

1. `BotService` refreshes the wiki-root registry whenever bot tools are created or updated. The registry stores the physical root folder node ID, root path, location, optional Collectives ID, and the active bot assignment.
2. App enable and app upgrade enqueue `RebuildWikiRootRegistryJob`, which backfills registry entries for existing personal bots with wiki tools.
3. `WikiFileEventListener` receives Nextcloud node events for create, write, delete, rename, and copy.
4. `WikiFileEventSyncService` ignores managed files (`index.md`, `log.md`, `schema.md`), hidden paths, and unsupported extensions. For the remaining node, it collects ancestor node IDs through `getId()` and `getParent()`.
5. The service queries only `educai_wiki_roots.root_node_id IN (...)` and schedules at most one `SyncWikiRootIndexJob` per root. `IJobList::has()` prevents duplicate queued jobs, and a five-second delay groups editor and upload spikes.
6. `SyncWikiRootIndexJob` loads the registered root, calls `WikiService::syncIndexForRoot()`, and writes either `last_synced_at` or `last_error` back to `educai_wiki_roots`.

The matching key is the Nextcloud node ID, not the path. `root_path` is stored for debugging and as a CLI background-job fallback when `IRootFolder::getById()` cannot resolve a folder in the current execution context. This keeps personal-file wikis and Collectives-backed wikis on the same path-independent implementation.

## Talk Bot Registration

The app includes a repair step that registers the shared `Talk AI` Talk bot when the app is installed or enabled. `SettingsService` refreshes registration after relevant settings changes. `TalkEnabledListener` handles the case where Talk becomes available after Talk AI.

If automatic registration cannot run because the environment lacks a usable CLI or Talk admin session, admins can still register the Talk bot with `occ talk:bot:install`.

## Security Notes

- Webhook requests use an HMAC secret.
- Admin secrets are encrypted through `CredentialService`.
- Nextcloud permission checks protect file access.
- Shared bot publishing uses approval checks tied to scopes.
- Admins register MCP tools centrally before users can assign them.
- Tool iteration, output size, request timeout, and rate-limit controls reduce blast radius.
