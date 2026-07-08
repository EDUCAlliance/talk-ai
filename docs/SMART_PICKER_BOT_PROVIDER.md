# Smart Picker Bot Provider

This document describes the Smart Picker integration that allows users to invoke AI bots via the "/" trigger in Nextcloud Talk.

## Overview

The Smart Picker is Nextcloud's cross-application feature for inserting content by typing "/". EducAI integrates with this system to provide bot selection directly from the Talk message input.

**Key Features:**
- Invoke bots using `/botname` instead of `@botname`
- Shows all accessible bots (personal + public) in a searchable list
- Only appears in Nextcloud Talk (not in Text, Mail, Deck, etc.)

## How It Works

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Nextcloud Talk                            │
├─────────────────────────────────────────────────────────────────┤
│  User types "/"  ──►  Smart Picker triggered                     │
│                              │                                   │
│                              ▼                                   │
│                    ┌─────────────────┐                          │
│                    │ Provider List   │◄── /ocs/v2.php/          │
│                    │ - AI Bots       │    references/providers  │
│                    │ - Files         │                          │
│                    │ - Links         │                          │
│                    └────────┬────────┘                          │
│                             │                                   │
│                    User selects "AI Bots"                       │
│                             │                                   │
│                             ▼                                   │
│                    ┌─────────────────┐                          │
│                    │ BotPickerElement│◄── Custom Vue component  │
│                    │ - Search bots   │                          │
│                    │ - Select bot    │                          │
│                    └────────┬────────┘                          │
│                             │                                   │
│                    User selects a bot                           │
│                             │                                   │
│                             ▼                                   │
│                    "/catalogue" inserted into message           │
│                             │                                   │
│                    User sends message                           │
│                             │                                   │
│                             ▼                                   │
│                    TalkHandler detects /mention                 │
│                    Bot processes message                        │
└─────────────────────────────────────────────────────────────────┘
```

### Components

| Component | File | Purpose |
|-----------|------|---------|
| PHP Provider | `lib/Reference/BotReferenceProvider.php` | Registers provider with Smart Picker |
| Event Listener | `lib/Listener/BotPickerListener.php` | Loads JS when Smart Picker renders |
| JavaScript Entry | `src/bot-picker.js` | Talk context detection, picker registration |
| Vue Component | `src/components/BotPickerElement.vue` | Bot selection UI |

## Usage

### For Users

1. **Open Nextcloud Talk** and navigate to a conversation
2. **Type `/`** in the message input field
3. **Select "AI Bots"** from the Smart Picker provider list
4. **Search or browse** available bots
5. **Click a bot** to insert its mention (e.g., `/catalogue`)
6. **Send the message** to invoke the bot

### Bot Mention Formats

The system supports two mention formats:

| Format | Example | Where it works |
|--------|---------|----------------|
| `@mention` | `@catalogue` | Traditional Talk mention |
| `/mention` | `/catalogue` | Smart Picker (new) |

Both formats are recognized by the webhook handler and work identically.

## Configuration

### Enabling/Disabling

The Smart Picker bot provider is automatically enabled when the EducAI app is installed. No additional configuration is required.

### Talk-Only Restriction

The bot picker only appears in Nextcloud Talk. This is enforced by JavaScript context detection that checks for:
- `window.OCA.Talk` or `window.OCA.Spreed` objects
- DOM element with `id="spreed"`
- URL patterns containing `/apps/spreed` or `/call/`

## API Endpoints

### Check Registered Providers

```bash
curl -u USER:PASSWORD -H "OCS-APIRequest: true" \
  "https://your-nextcloud.com/ocs/v2.php/references/providers"
```

Look for:
```json
{
  "id": "educai-bots",
  "title": "AI Bots",
  "icon_url": "https://your-nextcloud.com/apps/educai/img/app.svg",
  "order": 15
}
```

### Fetch Available Bots

The picker fetches bots from:
- `GET /apps/educai/api/v1/public-bots` - Public/group bots accessible to user
- `GET /apps/educai/api/v1/bots` - User's personal bots

## Troubleshooting

### Provider Not Appearing in Smart Picker

1. **Hard refresh the page** (Ctrl+Shift+R / Cmd+Shift+R)

2. **Check provider registration:**
   ```bash
   curl -u USER:PASSWORD -H "OCS-APIRequest: true" \
     "https://your-nextcloud.com/ocs/v2.php/references/providers" | grep educai
   ```

3. **Check browser console** (F12) for errors:
   - Look for `[EducAI] Bot picker:` messages
   - Should see "Detected Talk via..." and "Successfully registered"

4. **Check Nextcloud logs:**
   ```bash
   docker exec -u www-data CONTAINER tail -f /var/www/html/data/nextcloud.log | grep -i educai
   ```

5. **Verify JS file exists:**
   ```bash
   ls -la apps/educai/js/educai-bot-picker.mjs
   ```

### Smart Picker Not Triggering

The Smart Picker is triggered by typing "/" at the start of a line or after a space. If it's not appearing:

1. Ensure you're in a Talk conversation (not other apps)
2. Try typing "/" at the beginning of the message
3. Check if other Smart Picker providers work (like "Files")

### Bots Not Loading in Picker

1. **Check API access:**
   ```bash
   curl -u USER:PASSWORD \
     "https://your-nextcloud.com/apps/educai/api/v1/public-bots"
   ```

2. **Verify bots exist** in the EducAI admin panel

3. **Check bot visibility** settings (global, group, team)

### "/mention" Not Recognized

If sending `/botname` doesn't trigger the bot:

1. Verify the webhook is configured correctly (see `WEBHOOK_DEBUG_GUIDE.md`)
2. Check that both `@` and `/` mentions are handled in `TalkHandler.php`
3. Verify the bot is active and accessible to the user

## Development

### Rebuilding JavaScript

After modifying Vue components or JS files:

```bash
cd apps/educai
npm run build
```

### Testing Provider Registration

```php
// In a test controller or occ command
$referenceManager = \OC::$server->get(\OCP\Collaboration\Reference\IReferenceManager::class);
$providers = $referenceManager->getDiscoverableProviders();
foreach ($providers as $provider) {
    echo $provider->getId() . ': ' . $provider->getTitle() . "\n";
}
```

### Debug Logging

The provider includes debug logging. Enable Nextcloud debug mode in `config.php`:

```php
'debug' => true,
'loglevel' => 0,
```

Then check logs for `[EducAI] BotReferenceProvider` messages.

## Technical Details

### Provider ID

The provider ID `educai-bots` must match between:
- PHP: `BotReferenceProvider::getId()` returns `'educai-bots'`
- JS: `registerCustomPickerElement('educai-bots', ...)`

### Order Value

The provider order (15) determines position in the Smart Picker list:
- 0: Files (highest priority)
- 10: Common integrations
- 15: AI Bots
- 20+: Less common providers

### Reference Resolution

When a `/botname` reference is resolved (for link previews), the provider:
1. Validates the mention format
2. Looks up the bot by mention name
3. Checks user access permissions
4. Returns bot info (name, description, icon)

## Related Documentation

- [BOT_SETUP_GUIDE.md](BOT_SETUP_GUIDE.md) - Creating and configuring bots
- [WEBHOOK_DEBUG_GUIDE.md](WEBHOOK_DEBUG_GUIDE.md) - Debugging Talk integration
- [QUICK_START.md](QUICK_START.md) - Getting started with EducAI

