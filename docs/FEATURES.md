# Talk AI Feature Guide

This guide describes the product surface of Talk AI. Use it when you need to understand what the app offers without reading the PHP and Vue code first.

## The App Page

Everything a user does with bots happens on the Talk AI app page:

- **Discover** lists the bots a user may use, with search and a filter per audience. A tile opens the details and "Use in Talk".
- **My bots** lists the user's own bots with their state: active, draft, in review, change in review.
- **New bot** starts from a template and leads through five steps: basics, who uses it, knowledge, capabilities, check and finish. A sidebar shows the result, and a preview chat answers with the instruction and the model of the form before the bot exists.
- The **bot page** has an overview, the history of creation and approval, and the settings in tabs. A save bar collects the changes and says when they go into review first.
- **To review** is shown to people with approval rights. A review names the reason, the instruction, the knowledge sources and, for an approved bot, exactly what the change alters.
- **Activity** shows a user's own conversations with bots.
- **How Talk AI works** starts a tour over these pages; a short guide stays reachable from it.
- The **personal assistant** is one personal bot per user. Discover and My bots invite to set it up; afterwards it is pinned in first place.

The personal settings only point to the app page.

## Bot Management

Users create bots from the Talk AI app. A bot stores:

- display name
- Talk mention name
- system prompt
- visibility scope
- model selection
- optional custom temperature
- RAG state
- whether it also answers guests
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

Each scope has its own colour, one of the four of the EDUC logo: violet for personal, green for groups, orange for teams and pink for global. The colour always comes with an icon and the words.

### Guests

Guests are people who take part in a public Talk conversation without being signed in: by link, by e-mail invitation or through a bridge. A bot answers them only when **Also answer guests** is turned on. The switch is on the bot page under **Advanced**, and under **Advanced** in the last step of **New bot**.

- The switch is independent of the visibility scope. It opens nothing for signed-in users: a personal bot with the switch on still answers no other account.
- For a shared bot the switch belongs to the reviewed version. Turning it on or off on an approved bot is a change in review, and the approved setting stays live until then.
- Guests use a bot in conversations where a signed-in person who may use the bot has activated it. They cannot activate a bot, answer its activation questions or reset it with `((RESET))`.
- A bot that is not live for its audience (draft, first review) does not answer guests either.
- Guests get the same answers as everyone else, including what the bot knows from its knowledge sources. Turn the switch on only for bots whose knowledge may leave the organisation.
- Bots that were shared with everyone before version 3.0.0 answered guests already; the upgrade turns the switch on for them.

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
