# Talk AI

Talk AI is a Nextcloud app for running several AI bots in Nextcloud Talk. It connects Talk messages, bot-specific prompts, OpenAI-compatible model endpoints, file knowledge, and tool calls in one app-managed workflow.

The app targets institutions that need more than one generic assistant. A bot can stay personal, serve a group or team, or become globally available after approval. Admins control providers, credentials, rate limits, fallback behavior, and tool access.

## What It Does

- Creates Talk bots with their own prompt, mention name, model, temperature, visibility, and tool set.
- Supports personal, group, team, and global bot scopes, including approval flows for shared bots.
- Uses OpenAI-compatible chat, model-list, embedding, vision, and speech endpoints.
- Lets admins configure a primary endpoint, an optional secondary endpoint, and one fallback model.
- Adds knowledge through RAG sources from Nextcloud files and folders, plus optional Docling conversion for PDF, Office, and image formats.
- Provides built-in tools for RAG search, room-document search, image analysis, audio transcription, and persistent personal wikis - plus an extension point for companion apps to contribute their own tools.
- Registers external MCP tools that admins approve and users assign per bot.
- Integrates with Nextcloud Talk through a shared Talk AI Talk bot, Smart Picker support, and signature-verified webhooks.

## Current App Areas

### Bot Management

Users create and manage bots in the Talk AI app. Each bot has a stable `@mention`, its own system prompt, optional custom temperature, optional model selection, and a visibility scope.

Shared bots use the approval workflow. Personal bots stay private and can use the personal Markdown wiki tools.

### Model Providers

Admins configure model access in the Talk AI admin settings:

- primary chat endpoint and API key
- optional secondary chat endpoint and API key
- single-model or per-bot model selection
- allowed model list from `/v1/models`
- fallback model for eligible timeout or connection failures
- chat, streaming, and model-list timeouts
- default temperature for bots without a custom value

Legacy unprefixed model names resolve against the primary endpoint. New endpoint-aware model IDs use `primary:<model>` or `secondary:<model>`.

### Knowledge And Tools

Bots can use several knowledge sources and tools:

- RAG over indexed files and folders
- room-document search for files uploaded in the current Talk room
- image attachment analysis
- audio attachment transcription
- persistent Markdown wiki tools for personal bots
- admin-registered MCP tools

Tool results stay in the current agent turn and are not stored as conversation history.

## Quick Start

Install the app in a Nextcloud apps directory:

```bash
cd /path/to/nextcloud/apps-extra
git clone https://github.com/EDUCAlliance/talk-ai.git educai
cd educai
npm install
npm run build
```

Enable it from the Nextcloud app UI or with `occ`:

```bash
sudo -u www-data php occ app:enable educai
```

Then open **Administration settings > Talk AI** and configure at least:

- Primary API Endpoint
- Primary API Key
- Default Model or allowed model list
- Webhook Secret

Talk AI attempts to register the shared Talk bot during app setup and when relevant settings change. If automatic registration cannot run in your environment, register it manually:

```bash
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "your-webhook-secret" \
  "https://your-nextcloud.example/index.php/apps/educai/webhook/talk"
```

The webhook URL must include `/index.php` on installations that need it for app routes.

## About This Project

Talk AI is developed within [EDUC - the European Digital UniverCity](https://educalliance.eu),
an alliance of European universities, where it runs as the "EDUC AI" assistant
on the alliance-wide Nextcloud portal. The app is generic: it works with any
OpenAI-compatible endpoint and any Nextcloud 30-34 installation.

Deployment-specific functionality (for example EDUC's course-catalogue search
tools) lives in separate companion apps that plug into the tool-provider
extension point - see [docs/TOOL_PROVIDERS.md](docs/TOOL_PROVIDERS.md).

License: AGPL-3.0-or-later.

## Documentation

- [Documentation index](./docs/README.md)
- [Feature guide](./docs/FEATURES.md)
- [Architecture](./docs/ARCHITECTURE.md)
- [Development](./docs/DEVELOPMENT.md)
- [Quick start guide](./docs/QUICK_START.md)
- [Bot setup guide](./docs/BOT_SETUP_GUIDE.md)
- [Troubleshooting](./docs/TROUBLESHOOTING.md)
- [RAG tool guide](./docs/RAG_TOOL_GUIDE.md)
- [RAG background jobs](./docs/RAG_BACKGROUND_JOBS_GUIDE.md)
- [MCP tool-calling analysis](./docs/MCP_TOOL_CALLING_ANALYSIS.md)

## Development Snapshot

Requirements:

- Nextcloud 30 to 33
- PHP 8.1 or newer
- Node.js 22 and npm 10.5 or newer
- Composer dependencies from the surrounding Nextcloud workspace

Common commands:

```bash
npm run build
npm run watch
npm run lint
npm run stylelint
vendor/bin/phpunit --bootstrap tests/unit/bootstrap.php tests/unit
```

Migrations run during app enable or upgrade:

```bash
sudo -u www-data php occ upgrade --no-interaction
sudo -u www-data php occ migrations:status educai
```

See [Development](./docs/DEVELOPMENT.md) for the current build, test, and local Nextcloud verification notes.

## License

AGPL-3.0-or-later.
