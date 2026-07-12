# Webhook Debugging

What to check when a bot doesn't respond in Talk.

## Diagnostic Steps

**1. Bot activated in the room?** Open the conversation settings (⚙️) and confirm "Talk AI" is active in the bots list.

**2. Webhook registered?**

```bash
sudo -u www-data php occ talk:bot:list
```

You should see the `Talk AI` bot with `state = 1` and features `webhook, response`. A growing `error_count` means Talk can't deliver webhooks — usually a wrong URL (see below).

**3. Watch the log while mentioning the bot:**

```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep -i educai
```

A successful interaction logs this sequence:

```
========== Talk AI Webhook Received ==========
Extracted webhook data (message: "@mybot hello", room_token: "xxx")
Bot detected (bot_id: 1, mention_name: @mybot)
Calling LLM API
Got LLM response
Successfully sent reply to Talk
========== Webhook Processing Complete ==========
```

Where the sequence stops tells you what failed:

| Log stops at | Likely cause |
|---|---|
| Nothing at all | Bot not activated in the room, wrong webhook URL, or network issue |
| "Invalid webhook signature" | Webhook secret in settings ≠ secret used in `talk:bot:install` |
| "No bot mention found" | Typo or case mismatch in the @mention name, or the bot doesn't exist |
| "Failed to get LLM response" | Wrong endpoint/key/model, or no network route to the provider |
| "Failed to send reply to Talk" | Talk API issue or invalid room token |

## Common Issues

### 405 Method Not Allowed — webhook URL missing `/index.php`

The most common problem. The webhook URL **must include `/index.php`** on installations that route apps through it:

- ❌ `https://cloud.example.com/apps/educai/webhook/talk`
- ✅ `https://cloud.example.com/index.php/apps/educai/webhook/talk`

Fix by re-registering:

```bash
sudo -u www-data php occ talk:bot:uninstall "Talk AI"
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "your-webhook-secret" \
  "https://cloud.example.com/index.php/apps/educai/webhook/talk"
```

### Signature verification fails

The secret saved in Talk AI settings must exactly match the one passed to `talk:bot:install`.

1. Generate a secret: `openssl rand -hex 32`
2. Save it in the Talk AI admin settings.
3. Re-register the bot with the same secret (commands above).

For local testing only, an empty webhook secret skips verification.

### "Sorry, I'm having trouble connecting…"

The webhook works; the LLM call fails. Verify endpoint, key, and model name, and test directly:

```bash
curl https://your-api-endpoint/v1/chat/completions \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"your-model","messages":[{"role":"user","content":"test"}]}'
```

## Payload Reference

Talk delivers messages as signed POST requests. Useful when testing by hand:

```
Message text:  $payload['object']['content']  → JSON-decode, read the 'message' key
Room token:    $payload['target']['id']
User ID:       $payload['actor']['id']
Message ID:    $payload['object']['id']       → string in the payload, used as int
```

Replies back to Talk are signed with:

```php
'X-Nextcloud-Talk-Bot-Random'    => $random,
'X-Nextcloud-Talk-Bot-Signature' => hash_hmac('sha256', $random . $message, $secret)
```

Manual webhook test:

```bash
cat > /tmp/test-webhook.json <<'EOF'
{
  "type": "Create",
  "actor": { "type": "user", "id": "admin", "name": "Admin" },
  "object": { "type": "message", "id": 1,
              "content": "{\"message\":\"@mybot test\",\"parameters\":[]}" },
  "target": { "type": "chat", "id": "test-room-token", "name": "Test Room" }
}
EOF

curl -X POST http://localhost/index.php/apps/educai/webhook/talk \
  -H "Content-Type: application/json" \
  -d @/tmp/test-webhook.json \
  -w "\nHTTP: %{http_code}\n"
```

(Without valid signature headers this exercises routing and parsing, not the full flow.)

## Advanced

```bash
# raise log level
sudo -u www-data php occ log:manage --level debug

# check delivery failures
sudo -u www-data php occ talk:bot:list   # error_count column
```

If it still doesn't work, open an issue with the log excerpt (from "Webhook Received" to the failure), your Nextcloud version, and whether the LLM endpoint works when called directly.
