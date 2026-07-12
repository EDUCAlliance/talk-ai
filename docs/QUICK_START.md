# Talk AI Quick Start

This guide gets a working Talk AI installation from source into Nextcloud.

## 1. Install And Build

Put the app into a Nextcloud apps directory:

```bash
cd /path/to/nextcloud/apps-extra
git clone https://github.com/EDUCAlliance/talk-ai.git educai
cd educai
npm ci
npm run build
```

Enable the app:

```bash
cd /path/to/nextcloud
sudo -u www-data php occ app:enable educai
```

Run upgrade if Nextcloud reports pending migrations:

```bash
sudo -u www-data php occ upgrade --no-interaction
sudo -u www-data php occ migrations:status educai
```

## 2. Configure The Admin Settings

Open **Administration settings > Talk AI** and configure the required model settings:

- Primary API Endpoint
- Primary API Key
- Default Model
- Webhook Secret

Optional settings you can add later:

- secondary endpoint and fallback model
- allowed model list for per-bot model selection
- RAG and embedding endpoint
- Docling document conversion
- vision and speech endpoints
- rate limits
- MCP tools

Save settings before testing Talk.

## 3. Register The Talk Bot

Talk AI tries to register the shared `Talk AI` Talk bot when the app is enabled and when settings change. Check registration with:

```bash
sudo -u www-data php occ talk:bot:list
```

If the bot is missing, register it manually:

```bash
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "your-webhook-secret" \
  "https://your-nextcloud.example/index.php/apps/educai/webhook/talk"
```

The webhook URL must include `/index.php` on installations that route apps through that path. Disabling and re-enabling the app also re-runs the automatic registration.

## 4. Create A Bot

Open the Talk AI app and create a bot:

- choose a bot name
- choose a Talk mention such as `@supportbot`
- write the system prompt
- choose visibility: personal, groups, teams, or global
- choose a model if per-bot model selection is enabled
- enable RAG, tools, or a personal wiki if needed

Shared bots may start as drafts or pending approvals depending on the user's scope rights.

## 5. Activate Talk AI In A Talk Room

Open a Talk conversation and activate the shared `Talk AI` bot from the room settings or Smart Picker flow.

Then mention your app-level bot:

```text
@supportbot Can you summarize the project plan?
```

The shared Talk bot receives the webhook. Talk AI resolves `@supportbot`, checks access, runs the configured model and tools, and sends the answer back to Talk.

## 6. Check Logs

Watch Nextcloud logs while testing:

```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep -i educai
```

Common checks:

- `talk:bot:list` shows the shared Talk bot.
- The webhook URL contains `/index.php` when the installation needs it.
- The admin endpoint, key, and model are valid.
- The bot visibility allows the current user or room participants.
- Background jobs or `cron.php` run when testing RAG indexing.

See [Troubleshooting](./TROUBLESHOOTING.md) for deeper debugging.
