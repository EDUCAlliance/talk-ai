# Talk AI Webhook Debugging Guide

## Quick Diagnostic Steps

### 1. Check if Bot is Activated in Talk Room

1. Open the Talk conversation
2. Click the settings (⚙️) icon
3. Look for "Talk AI" or "Talk AI 2" in the bots list
4. Ensure it's **activated** (should have a checkmark)

### 2. Verify Webhook Registration

```bash
sudo -u www-data php occ talk:bot:list
```

Expected output:
```
+----+-----------+-------------+-------------+-------+-------------------+
| id | name      | description | error_count | state | features          |
+----+-----------+-------------+-------------+-------+-------------------+
| 1  | Talk AI   |             | 0           | 1     | webhook, response |
+----+-----------+-------------+-------------+-------+-------------------+
```

### 3. Watch Logs in Real-Time

Open a terminal and run:
```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep -i educai
```

Keep this running, then send a message in Talk like:
```
@catalogue hello
```

### 4. What to Look For in Logs

#### ✅ **Successful Flow:**
```
[INFO] ========== Talk AI Webhook Received ==========
[INFO] Received Talk webhook
[INFO] Extracted webhook data (message: "@catalogue hello", room_token: "xxx")
[INFO] Bot detected (bot_id: 1, mention_name: @catalogue)
[INFO] Processing message for bot
[INFO] Calling LLM API
[INFO] Got LLM response (response_length: 123)
[INFO] Sending reply to Talk
[INFO] Successfully sent reply to Talk
[INFO] Bot response sent successfully to Talk
[INFO] ========== Webhook Processing Complete ==========
```

#### ❌ **Common Issues:**

**No webhook received at all:**
- Bot not activated in Talk room
- Wrong webhook URL in bot registration
- Network issue

**"Invalid webhook signature":**
- Webhook secret mismatch
- Check: Settings → Webhook Secret matches `occ talk:bot:install` command

**"No bot mention found":**
- Typo in mention name
- Case sensitivity (should match exactly)
- Bot exists in database?

**"Failed to get LLM response":**
- API endpoint wrong
- API key invalid
- Network connectivity to LLM provider
- Model doesn't exist

**"Failed to send reply to Talk":**
- Talk API issue
- Check bot signature headers
- Room token invalid

### 5. Manual Webhook Test

```bash
# Create a test payload (modify with your actual data)
cat > /tmp/test-webhook.json <<'EOF'
{
  "type": "Create",
  "actor": {
    "type": "user",
    "id": "admin",
    "name": "Admin"
  },
  "object": {
    "type": "message",
    "id": 1,
    "name": "@catalogue test message",
    "content": "@catalogue test message"
  },
  "target": {
    "type": "chat",
    "id": "test-room-token",
    "name": "Test Room"
  }
}
EOF

# Test the webhook
curl -X POST \
  http://localhost/index.php/apps/educai/webhook/talk \
  -H "Content-Type: application/json" \
  -d @/tmp/test-webhook.json \
  -w "\nHTTP: %{http_code}\n"
```

### 6. Check Bot Exists in Database

```bash
sudo -u www-data php -r "
require '/var/www/html/lib/base.php';
\$bots = \OC::$server->get('OCA\EducAI\Db\BotMapper')->findAll();
foreach (\$bots as \$bot) {
    echo 'Bot: ' . \$bot->getBotName() . ' (@' . \$bot->getMentionName() . ')' . PHP_EOL;
}
"
```

### 7. Verify Settings are Saved

```bash
sudo -u www-data php -r "
require '/var/www/html/lib/base.php';
\$settings = \OC::$server->get('OCA\EducAI\Db\SettingsMapper')->getSettings();
echo 'API Endpoint: ' . \$settings->getApiEndpoint() . PHP_EOL;
echo 'Model: ' . \$settings->getDefaultModel() . PHP_EOL;
echo 'Has API Key: ' . (!empty(\$settings->getApiKey()) ? 'Yes' : 'No') . PHP_EOL;
echo 'Has Webhook Secret: ' . (!empty(\$settings->getWebhookSecret()) ? 'Yes' : 'No') . PHP_EOL;
"
```

## Common Issues & Solutions

### Issue: Bot Never Responds

**Check List:**
1. ✅ Bot activated in Talk room
2. ✅ Webhook registered with correct URL
3. ✅ Webhook secret matches in settings and OCC command
4. ✅ Bot exists in database with correct @mention
5. ✅ API endpoint and key configured
6. ✅ Network connectivity to LLM provider

**Solution:**
- Follow the diagnostic steps above
- Check logs for specific error messages
- Verify each component individually

### Issue: "Sorry, I'm having trouble connecting..."

This means the bot is working but the LLM API call is failing.

**Check:**
- API Endpoint is correct
- API Key is valid
- Model name is correct for your provider
- Network can reach the API endpoint from Nextcloud server

### Issue: Webhook Returns 500 Error

**Fix Applied in v2.0.0:**
- Changed `Response` constructor to proper format
- Added try-catch to always return 200 OK
- This prevents Talk from retrying failed webhooks

### Issue: 405 Method Not Allowed

**Symptom:** Talk logs show "POST resulted in a 405 Method Not Allowed"

**Cause:** Webhook URL missing `/index.php` prefix

**Fix:**
```bash
# Uninstall incorrect registration
sudo -u www-data php occ talk:bot:uninstall "Talk AI"

# Re-register with CORRECT URL (note /index.php)
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "your-secret-here" \
  "http://nextcloud.local/index.php/apps/educai/webhook/talk"
```

**URL Comparison:**
- ❌ `http://nextcloud.local/apps/educai/webhook/talk`
- ✅ `http://nextcloud.local/index.php/apps/educai/webhook/talk`

### Issue: Type Error - Argument #3 must be int

**Symptom:** "Argument #3 ($replyToId) must be of type int, string given"

**Cause:** Message ID from webhook is a string

**Fix:** Already fixed in v2.0.0 - message ID is cast to int

### Issue: Message Content Not Parsed

**Symptom:** Bot processes JSON string instead of actual message

**Cause:** Talk sends message as JSON-encoded string in `object.content`

**Example payload:**
```json
"content": "{\"message\":\"@catalogue test\",\"parameters\":[]}"
```

**Fix:** Already fixed in v2.0.0 - content is JSON-decoded

### Issue: Signature Verification Fails

**Temporary Workaround:**
Leave webhook secret empty in settings - signature verification will be skipped (only for testing!)

**Proper Fix:**
1. Generate a secure secret: `openssl rand -hex 32`
2. Save in Talk AI settings
3. Re-register bot with same secret:
```bash
sudo -u www-data php occ talk:bot:uninstall "Talk AI"
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "your-secret-here" \
  "http://nextcloud.local/index.php/apps/educai/webhook/talk"
```

## Testing Checklist

- [ ] Bot appears in `occ talk:bot:list`
- [ ] Bot is activated in Talk room
- [ ] Bot exists in database (check with query above)
- [ ] Settings are configured (API endpoint, key, model, webhook secret)
- [ ] Logs show "Webhook Received" when mentioning bot
- [ ] Logs show "Bot detected"
- [ ] Logs show "Calling LLM API"
- [ ] Logs show "Got LLM response"
- [ ] Logs show "Bot response sent successfully"
- [ ] Message appears in Talk

## Advanced Debugging

### Enable Full Debug Logging

```bash
sudo -u www-data php occ log:manage --level debug
```

### Watch All Logs (Not Just educai)

```bash
tail -f /path/to/nextcloud/data/nextcloud.log
```

### Check Talk Bot Errors

```bash
sudo -u www-data php occ talk:bot:list
# Look at the error_count column
```

### Test LLM Connection Directly

```bash
# Test if LLM endpoint is reachable
curl -v https://chat-ai.academiccloud.de/v1/chat/completions \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"mistral-large-instruct","messages":[{"role":"user","content":"test"}]}'
```

## Need Help?

If the bot still doesn't work after following these steps:

1. Save the output of the diagnostic commands above
2. Copy the relevant log entries (from "Webhook Received" to "Processing Complete")
3. Note any error messages
4. Check if the LLM API works when called directly
5. Open an issue with all this information

## Success Indicators

When everything works, you'll see:
- ✅ Webhook logs appear when you mention the bot
- ✅ Bot appears to be "typing" in Talk
- ✅ Response appears in the conversation
- ✅ No errors in Nextcloud logs
- ✅ error_count = 0 in `talk:bot:list`

## Actual Working Log Example

From a successful bot interaction:

```
[INFO] ========== Talk AI Webhook Received ==========
[INFO] Received Talk webhook (payload_keys: type, actor, object, target)
[INFO] Extracted webhook data (message: "@catalogue test", room_token: "4mcv3kcn")
[INFO] Bot detected (bot_id: 1, bot_name: "catalogue", mention_name: "@catalogue")
[INFO] Processing message for bot (clean_message: "test")
[INFO] Calling LLM API (message_count: 3)
[INFO] Got LLM response (response_length: 242)
[INFO] Got bot response, sending to Talk
[INFO] Sending reply to Talk (endpoint: .../bot/4mcv3kcn/message)
[INFO] Successfully sent reply to Talk
[INFO] Bot response sent successfully to Talk
[INFO] ========== Webhook Processing Complete ==========
```

## Critical Lessons Learned

### 1. Webhook URL Format
**Always use `/index.php` in the webhook URL:**
```bash
# ❌ WRONG - Results in 405 Method Not Allowed
"http://nextcloud.local/apps/educai/webhook/talk"

# ✅ CORRECT
"http://nextcloud.local/index.php/apps/educai/webhook/talk"
```

### 2. Message Content Parsing
Talk sends message content as **JSON-encoded string**:
```json
"content": "{\"message\":\"@catalogue test\",\"parameters\":[]}"
```

Must be decoded to extract actual message text.

### 3. Message ID Type
Message ID comes as string from webhook but must be int:
```php
$messageId = (int)($payload['object']['id'] ?? 0);
```

### 4. Bot Reply Signing
Replies to Talk must include signature headers:
```php
'X-Nextcloud-Talk-Bot-Random' => $random,
'X-Nextcloud-Talk-Bot-Signature' => hash_hmac('sha256', $random . $message, $secret)
```

### 5. Payload Structure
```
Message text: $payload['object']['content'] (JSON decode → 'message' key)
Room token: $payload['target']['id']
User ID: $payload['actor']['id']
Message ID: $payload['object']['id'] (cast to int)
```

