# Talk AI Feature Guide

This guide describes the product surface of Talk AI. Use it when you need to understand what the app offers without reading the PHP and Vue code first.

## Bot Management

Users create bots from the Talk AI app. A bot stores:

- display name
- Talk mention name
- system prompt
- visibility scope
- model selection
- optional custom temperature
- RAG state
- enabled tool assignments
- onboarding questions for Talk rooms

The mention name becomes the Talk trigger. A room can contain several Talk AI bots, and each bot keeps its own conversation state.

## Visibility And Approval

Talk AI supports four visibility scopes:

- `personal`: visible only to the owner
- `groups`: visible to selected Nextcloud groups
- `teams`: visible to selected Nextcloud teams
- `global`: visible to all users

Personal bots do not need approval. Shared bots can move through draft, pending, and approved states. Users with scope rights can review pending bots for the groups or teams they manage. The approved version stays live while an update waits for review.

## Talk Integration

The app exposes one shared Talk bot named `Talk AI`. Individual Talk AI bots live inside the app and respond when users mention their configured name.

The Talk integration includes:

- HMAC-verified webhooks
- shared Talk bot registration on app setup and settings save
- Smart Picker support for activating Talk AI in rooms
- moderator checks before room activation
- per-room onboarding and response mode
- mention-only or always-respond room behavior
- reset command support through `((RESET))`

## Model Providers

Talk AI uses OpenAI-compatible APIs. Admins configure:

- primary chat endpoint and key
- optional secondary chat endpoint and key
- default model
- allowed model list for per-bot selection
- fallback model
- chat, streaming, and model-list timeouts
- default temperature

When multiple models are enabled, model IDs include their endpoint prefix, for example `primary:llama-3.3-70b-instruct` or `secondary:qwen3-coder-next`. Older raw model names still resolve against the primary endpoint.

Fallback behavior is narrow by design. A fallback model can retry a failed non-streaming request. For streaming requests, Talk AI retries only if the primary request fails before the first chunk reaches Talk.

## Knowledge Sources

### RAG Sources

Users can attach Nextcloud files and folders to a bot when the administrator enables RAG. Background jobs extract text, split it into chunks, embed those chunks, and store the vectors in the database.

Supported plain-text formats include:

- `.txt`
- `.md`
- `.csv`
- `.json`
- `.xml`

When Docling is enabled, Talk AI can also ingest PDF, Office documents, and supported image formats through the configured document-conversion endpoint.

### Room Documents

When users upload documents in a Talk room, Talk AI can index and search those current-room documents through the `room_search_documents` built-in tool. This keeps room files separate from long-lived bot RAG sources.

### Personal Markdown Wiki

Personal bots can use a persistent Markdown wiki. The wiki can live in the user's files under `Talk AI/Personal Wikis/<bot-slug>` or in an editable Collectives space.

Wiki tools can:

- search pages and source summaries
- read Markdown, text, or JSON pages, including large pages through `offset` and `limit`
- create, overwrite, or append pages
- append maintenance notes to `log.md`

Wiki tools are restricted to personal bots.

Each wiki root has an `index.md` page with an `Existing Files` section. Talk AI keeps that section current for both bot-driven wiki writes and manual file changes made through Files, WebDAV, Text, or Collectives. Manual file events are not handled synchronously in the save request. Instead, Talk AI matches the changed node against a registry of known wiki root node IDs and queues one deduplicated background job per physical wiki root.

The queued sync ignores the managed root files `index.md`, `log.md`, and `schema.md`, skips hidden paths, and tracks Markdown, text, and JSON content. This works for normal personal-file wikis and Collectives-backed wikis without depending on localized folder names such as `Collectives` or `Kollektive`.

`wiki_read_page` returns UTF-8 character pagination metadata: `offset`, `limit`, `returned_length`, `total_length`, `has_more`, and `next_offset`. The default read limit is 3000 characters and the server caps requests at 3500 characters so tool output stays below the agent-loop truncation limit. If a read response has `has_more=true`, the bot should continue with `offset=next_offset` before finishing an incomplete review, or tell the user the page path and `next_offset` needed to continue later.

## Tools

Talk AI has two tool families.

### Built-In Tools

Built-in tools run in the app:

- `rag_search_documents`
- `room_search_documents`
- `attachment_analyze_image`
- `attachment_transcribe_audio`
- `wiki_search`
- `wiki_read_page`
- `wiki_write_page`
- `wiki_log_event`

The visible tool list depends on admin settings and bot scope. For example, image tools require a vision endpoint, audio tools require a speech endpoint, and wiki tools require a personal bot.

Companion apps can contribute additional tools through the [tool-provider extension point](TOOL_PROVIDERS.md); contributed tools behave like built-ins.

### MCP Tools

Admins can register external MCP endpoints. Users can enable approved tools per bot. Talk AI fetches tool schemas from the MCP server, sends tool calls during the agent loop, and logs invocations in the Nextcloud log.

Tool results stay in memory for the active turn. Talk AI stores only user and assistant messages in `educai_conversations`.

## Admin Controls

The admin settings page is organized into sections:

- Essentials
- Model Endpoints
- Fallback & Timeouts
- RAG & Embeddings
- Document Conversion
- Media Tools
- Rate Limits
- Conversation Memory
- Agent Tools
- All Bots

Admin settings cover credentials, model access, embedding and conversion providers, rate-limit queueing, conversation context size, MCP tools, and bot administration.

## Conversation Memory

Talk AI keeps conversation history per bot and room. The runtime considers recent stored messages and trims the prompt to the configured token budget. The default conversation context budget is 8000 tokens.

Tool outputs do not become stored conversation messages. The final assistant response should synthesize the relevant tool output for the user.
