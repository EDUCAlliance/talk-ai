# Talk AI - Multi-Bot Setup Guide

## Overview

Talk AI enables you to create and manage multiple AI-powered bots for Nextcloud Talk. Each bot has its own personality, system prompt, and conversation history.

## Quick Start

### 1. Admin Configuration

Before users can create bots, an administrator must configure the LLM API settings:

1. Open the Talk AI app in Nextcloud
2. Scroll to the "Administrator Settings" section and click to expand
3. Configure the following:
   - **API Endpoint**: Your API endpoint URL (e.g., `https://chat-ai.academiccloud.de/v1/chat/completions`)
   - **API Key**: Enter your API key for the provider
   - **Default Model**: The model to use (e.g., `llama-3.3-70b-instruct`, `qwen3-32b`)
   - **Webhook Secret**: Generate a secure random string for webhook verification
4. Click "Save Settings"

**Note**: Talk AI is designed for the AcademicCloud (GWDG) SAIA API but supports any compatible endpoint:
- AcademicCloud (GWDG) SAIA: `https://chat-ai.academiccloud.de/v1/chat/completions` (Recommended)
- Custom providers: Any compatible endpoint

### 2. Register Bot with Nextcloud Talk

⚠️ **CRITICAL**: The webhook URL **must include `/index.php`** in the path!

Run this command in your Nextcloud installation directory:

```bash
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "YOUR_WEBHOOK_SECRET" \
  "https://nextcloud.local/index.php/apps/educai/webhook/talk"
```

Replace:
- `YOUR_WEBHOOK_SECRET` with the secret you configured in settings
- `your-nextcloud.com` with your Nextcloud domain

**Common Mistakes:**
- ❌ `https://your-nextcloud.com/apps/educai/webhook/talk` (missing /index.php)
- ✅ `https://your-nextcloud.com/index.php/apps/educai/webhook/talk` (correct)

For Docker installations:
```bash
docker exec -u www-data container-name php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "YOUR_WEBHOOK_SECRET" \
  "http://nextcloud.local/index.php/apps/educai/webhook/talk"
```

### 3. Activate Bot in Talk Rooms

1. Open a Nextcloud Talk conversation
2. Click the conversation settings (⚙️ icon)
3. Find "Talk AI" in the bots list
4. Click to activate the bot in this conversation

### 4. Create Your First Bot

1. Open the Talk AI app
2. Click "Create New Bot"
3. Fill in the form:
   - **Bot Name**: A friendly display name (e.g., "Support Helper")
   - **Mention Name**: The @mention trigger (e.g., `@supportbot`)
   - **System Prompt**: Define the bot's behavior and personality

Example system prompt:
```
You are a friendly IT support assistant. Help users troubleshoot technical 
issues with patience and clarity. Ask clarifying questions when needed and 
provide step-by-step solutions. Keep responses concise and actionable.
```

4. Click "Create Bot"

### 5. Use Your Bot in Talk

In any Talk conversation where Talk AI is activated:

```
@supportbot How do I reset my password?
```

The bot will respond based on its system prompt!

## Advanced Configuration

### Multiple Bots in the Same Room

You can have multiple bots active simultaneously:

```
@supportbot How do I troubleshoot email issues?
@hrbot What's our vacation policy?
@codehelp Can you review this SQL query?
```

Each bot maintains its own conversation history and responds independently.

### System Prompt Best Practices

**Good system prompts:**
- Define the bot's role clearly
- Specify the tone and style
- Set boundaries (what the bot should/shouldn't do)
- Include any domain-specific knowledge

**Example - HR Bot:**
```
You are an HR assistant for Acme Corp. Answer questions about company 
policies, benefits, and procedures. Always be professional and accurate.
If you don't know the answer, direct users to contact hr@acme.com.
Do not provide legal advice.
```

**Example - Code Review Bot:**
```
You are a senior software engineer specializing in PHP and Vue.js.
Review code for:
- Security vulnerabilities
- Best practices
- Performance issues
- Code style

Provide constructive feedback with specific examples and suggestions.
```

### Conversation History

- Each bot maintains conversation history per Talk room
- When generating replies, the context sent to the model is trimmed to a **token budget** (admin setting `conversationContextTokens`, default: 8000) and the server considers up to the most recent 50 stored messages.
- Users can continue conversations naturally (older messages may drop out of the model context as the conversation grows)

### Managing Bots

**Edit a Bot:**
1. Find the bot in the Talk AI app
2. Click "Edit"
3. Update the bot name or system prompt
4. Note: Mention names cannot be changed after creation

**Delete a Bot:**
1. Find the bot in the Talk AI app
2. Click "Delete"
3. Confirm deletion
4. All conversation history for this bot will be permanently deleted

## Supported LLM Providers

Talk AI is optimized for the AcademicCloud (GWDG) SAIA API but works with any compatible endpoint.

### AcademicCloud (GWDG) SAIA (Recommended)
- **API Endpoint**: `https://chat-ai.academiccloud.de/v1/chat/completions`
- **API Key**: Book access via [KISSKI LLM Service](https://docs.hpc.gwdg.de/services/saia/index.html)
- **Available Models**:
  - Text: `llama-3.3-70b-instruct`, `qwen3-32b`, `mistral-large-instruct`
  - Code: `qwen2.5-coder-32b-instruct`, `codestral-22b`
  - Reasoning: `qwq-32b`, `deepseek-r1`
  - Embeddings: `multilingual-e5-large-instruct`, `e5-mistral-7b-instruct`


## Troubleshooting

### UI Not Loading / Blank Page

If the bot management interface doesn't load:

1. **Check JavaScript Console (F12)**:
   - Look for `import.meta may only appear in a module` error
   - This means the build output format is wrong
   - **Fix**: Ensure `vite.config.js` has `format: 'iife'` in rollupOptions

2. **Verify Files Exist**:
   ```bash
   ls -lh /path/to/nextcloud/apps-extra/educai/js/educai-main.js
   # Should be ~2MB
   ```

3. **Check Template**:
   - Verify `templates/index.php` has `<div id="content"></div>`
   - Ensure no CSS file reference (CSS is inline-injected)

4. **Clear Cache**:
   ```bash
   sudo -u www-data php occ maintenance:mode --on
   sudo -u www-data php occ maintenance:mode --off
   ```

5. **Hard Refresh Browser**: Cmd+Shift+R (Mac) or Ctrl+Shift+F5 (Windows)

### Bot Doesn't Respond

**First, check if webhook URL has `/index.php`:**
```bash
# List registered bots and check error_count
sudo -u www-data php occ talk:bot:list
```

If `error_count` is increasing, the webhook URL is likely wrong.

**Fix with correct URL:**
```bash
sudo -u www-data php occ talk:bot:uninstall "Talk AI"
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "your-secret" \
  "https://your-domain/index.php/apps/educai/webhook/talk"
```

**Then check:**

1. **Bot activated in the room:**
   - Go to Talk conversation settings
   - Verify "Talk AI" is listed and active

2. **Verify mention name:**
   - Ensure you're using the exact @mention name
   - Mention names are case-sensitive

3. **Check webhook logs:**
   ```bash
   # Watch logs while testing
   tail -f /path/to/nextcloud/data/nextcloud.log | grep -i educai
   ```

4. **Verify API configuration:**
   - API Endpoint is correct
   - API Key is valid
   - Model name matches your provider

### "Invalid Signature" Error

This means the webhook secret doesn't match:

1. Regenerate a secure secret
2. Update in Talk AI settings
3. Re-register the bot with OCC:
```bash
sudo -u www-data php occ talk:bot:uninstall "Talk AI"
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "NEW_SECRET" \
  "https://your-nextcloud.com/index.php/apps/educai/webhook/talk"
```

### "Mention name already exists"

Each @mention name must be unique. Choose a different name for your bot.

### Bot Responses Are Slow

- LLM API response times vary (typically 2-10 seconds)
- Consider using faster models (e.g., `meta-llama-3.1-8b-instruct` vs `llama-3.3-70b-instruct`)
- Check your network connection to the LLM provider

### Conversation Context Lost

- The context sent to the LLM is limited by the configured token budget (`conversationContextTokens`). As conversations grow, older messages may drop out of the model context.
- Starting a new topic may help establish fresh context
- Consider creating specialized bots for different topics

### Migration Errors

If you encounter migration issues during app upgrades, ensure the app is enabled, run upgrade checks, then check migration status:
```bash
sudo -u www-data php occ app:enable educai
sudo -u www-data php occ upgrade --no-interaction
sudo -u www-data php occ migrations:status educai
```

### Build Errors

**"import.meta may only appear in a module":**
- Ensure `vite.config.js` has `format: 'iife'` in build.rollupOptions.output
- Rebuild: `npm run build`
- Move files to js/: `mv educai-main.js* js/`

**ESLint errors preventing build:**
- All critical errors have been fixed in v2.0.0
- Minor warnings about `<option>` formatting are non-blocking
- Build succeeds even with these warnings

## Security Considerations

1. **API Keys**: Stored encrypted in the database
2. **Webhook Signature**: HMAC SHA256 verification
3. **User Isolation**: Users can only edit/delete their own bots
4. **Admin Only**: API configuration restricted to administrators

## Use Cases

### IT Support Bot
```
@mention: @itsupport
System Prompt: "You are an IT support specialist. Help users with 
technical issues, password resets, software installation, and hardware 
troubleshooting. Be patient and provide step-by-step guidance."
```

### HR Policy Bot
```
@mention: @hrbot
System Prompt: "You are an HR assistant. Answer questions about company 
policies, benefits, vacation, sick leave, and general HR procedures. 
Always refer to official documentation and direct complex questions to HR."
```

### Code Review Bot
```
@mention: @codehelp
System Prompt: "You are a senior developer. Review code for bugs, 
security issues, and best practices. Provide constructive feedback with 
specific examples. Focus on PHP, JavaScript, and Vue.js."
```

### Meeting Assistant Bot
```
@mention: @scribe
System Prompt: "You are a meeting assistant. Summarize discussions, 
extract action items, and organize information clearly. Format responses 
with bullet points and clear sections."
```

## API Documentation

For developers who want to integrate with Talk AI programmatically:

### Bot Management API

**List Bots**
```http
GET /apps/educai/api/v1/bots
Authorization: Bearer <nextcloud-token>
```

**Create Bot**
```http
POST /apps/educai/api/v1/bots
Content-Type: application/json

{
  "botName": "My Bot",
  "mentionName": "@mybot",
  "systemPrompt": "You are..."
}
```

**Update Bot**
```http
PUT /apps/educai/api/v1/bots/{id}
Content-Type: application/json

{
  "botName": "Updated Name",
  "systemPrompt": "New prompt..."
}
```

**Delete Bot**
```http
DELETE /apps/educai/api/v1/bots/{id}
```

## Support

- **Documentation**: see the guides in [`docs/`](./README.md)
- **Issues**: https://github.com/EDUCAlliance/Nextcloud-EDUC-AI-Plugin/issues

## License

AGPL-3.0
