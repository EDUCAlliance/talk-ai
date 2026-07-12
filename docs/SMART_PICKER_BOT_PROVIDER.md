# Smart Picker Bot Provider

Talk AI registers a provider for Nextcloud's Smart Picker (the "/" menu), so users can pick a bot from a searchable list directly in the Talk message input.

- Invoke bots with `/botname` as an alternative to `@botname` — both are handled identically by the webhook handler.
- The list shows all bots the user can access (personal + shared).
- The picker only appears in Talk, not in Text, Mail, Deck, etc.

## How It Works

Typing "/" in Talk opens the Smart Picker provider list (fetched from `/ocs/v2.php/references/providers`). Selecting **AI Bots** opens a Vue component that searches the user's accessible bots and inserts the chosen mention (e.g. `/supportbot`) into the message. On send, `TalkHandler` resolves the `/mention` like a normal bot mention.

| Component | File | Purpose |
|---|---|---|
| PHP provider | `lib/Reference/BotReferenceProvider.php` | Registers provider id `educai-bots` with the Smart Picker |
| Event listener | `lib/Listener/BotPickerListener.php` | Loads the JS when the picker renders |
| JS entry | `src/bot-picker.js` | Talk context detection, picker registration |
| Vue component | `src/components/BotPickerElement.vue` | Bot search/selection UI |

The Talk-only restriction is enforced in JS by checking for `window.OCA.Talk`/`window.OCA.Spreed`, the `#spreed` DOM element, or `/apps/spreed`//`/call/` URL patterns.

The picker fetches bots from `GET /apps/educai/api/v1/bots` (personal) and `GET /apps/educai/api/v1/public-bots` (shared bots accessible to the user).

No configuration is needed — the provider is active as soon as the app is enabled.

## Troubleshooting

**Provider missing from the picker:**

1. Hard-refresh the page (Ctrl/Cmd+Shift+R).
2. Verify registration:
   ```bash
   curl -u USER:PASSWORD -H "OCS-APIRequest: true" \
     "https://cloud.example.com/ocs/v2.php/references/providers" | grep educai
   ```
3. Check the browser console for `[EducAI] Bot picker:` messages — you should see "Detected Talk via …" and "Successfully registered".
4. Verify the bundle exists: `ls js/educai-bot-picker.mjs` (rebuild with `npm run build`).

**Picker opens but no bots listed:** check that bots exist and their visibility (personal/groups/teams/global) includes the current user; test `GET /apps/educai/api/v1/public-bots` directly.

**`/mention` not triggering the bot:** the picker only inserts text — if sending it does nothing, debug the webhook path like any other non-responding bot ([WEBHOOK_DEBUG_GUIDE.md](WEBHOOK_DEBUG_GUIDE.md)).

## Technical Notes

- The provider id `educai-bots` must match between `BotReferenceProvider::getId()` and `registerCustomPickerElement('educai-bots', …)` in JS.
- Provider order is 15 (after Files at 0 and common integrations at 10).
- When a `/botname` reference is resolved for a link preview, the provider validates the mention, checks the user's access, and returns the bot's name, description, and icon.
