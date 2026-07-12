# Troubleshooting

## Bot Not Responding in Talk

**Most common cause: webhook URL missing `/index.php`.**

```bash
sudo -u www-data php occ talk:bot:list
```

If `error_count` is growing, the URL is likely wrong. Fix:

```bash
sudo -u www-data php occ talk:bot:uninstall "Talk AI"
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "your-webhook-secret" \
  "https://your-domain/index.php/apps/educai/webhook/talk"
```

- ❌ `https://domain/apps/educai/webhook/talk` → 405 error
- ✅ `https://domain/index.php/apps/educai/webhook/talk`

Then watch the log while mentioning the bot:

```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep -i educai
```

A working interaction logs `Webhook Received → Bot detected → Calling LLM API → Got LLM response → Bot response sent successfully`. For a step-by-step diagnosis of where the chain breaks, see [WEBHOOK_DEBUG_GUIDE.md](WEBHOOK_DEBUG_GUIDE.md).

## LLM Connection Issues

If the bot replies with an error about the AI service, the webhook works but the model call fails. Check endpoint, key, and model name, and test directly:

```bash
curl https://your-api-endpoint/v1/chat/completions \
  -H "Authorization: Bearer your-key" \
  -H "Content-Type: application/json" \
  -d '{"model":"your-model","messages":[{"role":"user","content":"test"}]}'
```

## Debug Logging

```bash
sudo -u www-data php occ log:manage --level debug
```

Or in `config/config.php` (remember to revert):

```php
'debug' => true,
'loglevel' => 0,   // 0 = debug … 3 = error
```

## Environment Checks

- Nextcloud 30–34 (`occ status`)
- PHP 8.1+ (`php -v`)
- App files readable by the web server (`chown -R www-data:www-data .../apps-extra/educai`)
- Frontend built (`npm ci && npm run build`), hard-refresh the browser after updates

## Reporting Bugs

Open an issue at https://github.com/EDUCAlliance/talk-ai/issues with your Nextcloud and PHP versions, the relevant `nextcloud.log` excerpt, and any browser console errors.
