# Chat Room Onboarding

When a bot is @mentioned in a Talk room for the first time, it enters **onboarding mode**: it introduces itself, asks how the user wants to interact, optionally asks custom questions, and stores the answers for future conversations.

## Flow

1. User mentions the bot for the first time (`@mybot hello`).
2. The bot asks whether it should respond **A:** only when @mentioned, or **B:** to every message in the chat. The user replies `A` or `B` — no @mention needed during onboarding.
3. If the bot has custom onboarding questions, it asks them next (branching supported). Otherwise onboarding completes immediately.
4. The bot confirms and is ready; answers are stored and injected into its system prompt from then on.

## Response Modes

- **`mention`** — bot only responds when @mentioned. Default; recommended for shared rooms.
- **`always`** — bot responds to every message. Useful for dedicated bot rooms or 1:1 chats.

## Custom Onboarding Questions

Bot creators can define up to **5 questions** per bot, with **multiple-choice branching** and **free-text** answers. Answers are stored permanently and appended to the LLM's system prompt.

Questions are stored as a JSON tree:

```json
{
  "start": "q1",
  "questions": [
    {
      "id": "q1",
      "text": "What is your main use case?",
      "answers": [
        { "id": "a", "text": "Learning", "next": "q2a" },
        { "id": "b", "text": "Teaching", "next": null }
      ]
    },
    {
      "id": "q2a",
      "text": "What subject interests you most?",
      "answers": [
        { "id": "a", "text": "Mathematics", "next": null },
        { "id": "b", "text": "Science", "next": null }
      ]
    }
  ]
}
```

- `start` — ID of the first question
- `next: "qX"` — continue with that question; `next: null` — end of the branch, onboarding completes
- `type: "free_text"` on an answer — the user replies with arbitrary text, stored verbatim:

```json
{
  "id": "q1",
  "text": "What is your current field of study?",
  "answers": [ { "id": "text", "type": "free_text", "next": null } ]
}
```

## How Answers Reach the LLM

Answers are stored per room in `educai_chat_rooms.onboarding_answers` as Q&A pairs:

```json
[
  { "question_id": "q1", "question_text": "What is your main use case?",
    "answer_id": "a", "answer_text": "Learning" }
]
```

On every message, `BotService::processMessage()` appends the onboarding context to the system prompt:

```
[Original System Prompt]

## User Onboarding Context
The user has provided the following information during onboarding:
- **What is your main use case?** → Learning

Use this context to personalize your responses.
```

System-prompt injection keeps the context persistent (unlike conversation history), token-efficient, and separate from the conversation flow.

## Reset Command

```
((RESET))           → resets all bots in the room
((RESET)) @mybot    → resets only that bot
```

Reset deletes the room state and the bot's conversation history for that room; the next mention triggers fresh onboarding. This is also the easiest way to test question flows.

## Message Routing

For each incoming message, `TalkHandler` checks in order:

1. `((RESET))` command → handle reset.
2. Bot @mentioned → load room state: no state → start onboarding; onboarding in progress → treat message as an answer; completed → process normally.
3. No mention → if a bot in this room is mid-onboarding, treat the message as its answer; else if an `always`-mode bot is active, process the message; otherwise ignore.

## Database Schema

`educai_chat_rooms`:

| Column | Description |
|---|---|
| `bot_id` | FK to `educai_bots` |
| `room_token` | Talk room identifier |
| `response_mode` | `mention` or `always` |
| `onboarding_status` | `mode_selection`, `questions`, or `completed` |
| `current_question_id` | question being asked (nullable) |
| `onboarding_answers` | JSON array of Q&A pairs |
| `activated_by` | user who first activated the bot |

`educai_bots.onboarding_questions` holds the JSON question tree (nullable).

## Key Files

| File | Purpose |
|---|---|
| `lib/Service/OnboardingService.php` | Onboarding flow logic |
| `lib/Webhook/TalkHandler.php` | Message routing and onboarding detection |
| `lib/Service/BotService.php` | Context injection into the system prompt |
| `lib/Db/ChatRoom.php` / `ChatRoomMapper.php` | Room state persistence |
| `src/components/BotForm.vue` | UI for configuring questions |

## Tips

- Keep questions short — users answer in a chat interface.
- Limit branching to 2–3 levels.
- Reference the onboarding answers in the system prompt so the bot actually uses them.
