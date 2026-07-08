# Chat Room Onboarding System

This document explains the onboarding system for Talk AI bots in Nextcloud Talk rooms.

## Overview

When a bot is activated in a chat room for the first time (via @mention), it enters **onboarding mode**. This allows the bot to:

1. Introduce itself to the user
2. Ask how the user wants to interact (mention-only vs. always respond)
3. Optionally ask custom onboarding questions to personalize responses
4. Store user preferences for future conversations

## Onboarding Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    User mentions bot first time                  │
│                         @mybot hello                             │
└─────────────────────────┬───────────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Welcome Message                               │
│  "You have activated the bot @mybot."                           │
│  "Would you like me to respond to:"                             │
│  "A: Only when you @mention me"                                 │
│  "B: Every message in this chat"                                │
│  "Reply with A or B."                                           │
└─────────────────────────┬───────────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│              User responds: "A" or "B"                          │
│         (No @mention required during onboarding)                │
└─────────────────────────┬───────────────────────────────────────┘
                          ▼
           ┌──────────────┴──────────────┐
           ▼                              ▼
┌─────────────────────┐      ┌─────────────────────────────────────┐
│ No custom questions │      │    Custom questions configured      │
│                     │      │                                     │
│ "Got it! I'll       │      │ "Got it! Before we start, I have   │
│  respond to..."     │      │  a few questions:"                  │
│ "I'm ready to help!"│      │                                     │
│                     │      │ Q1: "What is your main use case?"  │
│ ──► COMPLETED       │      │ - A: Learning                       │
└─────────────────────┘      │ - B: Teaching                       │
                             └─────────────────┬───────────────────┘
                                               ▼
                             ┌─────────────────────────────────────┐
                             │     User answers questions          │
                             │  (Branching paths supported)        │
                             └─────────────────┬───────────────────┘
                                               ▼
                             ┌─────────────────────────────────────┐
                             │ "Thanks! I've noted your            │
                             │  preferences. I'm ready to help!"   │
                             │                                     │
                             │ ──► COMPLETED                       │
                             └─────────────────────────────────────┘
```

## Response Modes

### Mention Mode (`mention`)
- Bot only responds when explicitly @mentioned
- Default and recommended for shared rooms
- Example: `@mybot What is the weather?`

### Always Mode (`always`)
- Bot responds to every message in the chat
- Useful for dedicated bot rooms or 1:1 chats
- No @mention required after onboarding

## Custom Onboarding Questions

Bot creators can define custom onboarding questions in the bot configuration. These questions:

- Support up to **5 questions** per bot
- Allow **multiple-choice branching** (A/B/C/… answers leading to different paths)
- Allow **free-text questions** where the user's typed answer is stored verbatim
- Are stored permanently and added to the LLM's system prompt

### Question Tree Structure

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
        { "id": "b", "text": "Teaching", "next": "q2b" }
      ]
    },
    {
      "id": "q2a",
      "text": "What subject interests you most?",
      "answers": [
        { "id": "a", "text": "Mathematics", "next": null },
        { "id": "b", "text": "Science", "next": null }
      ]
    },
    {
      "id": "q2b",
      "text": "What grade level do you teach?",
      "answers": [
        { "id": "a", "text": "Elementary", "next": null },
        { "id": "b", "text": "Secondary", "next": null }
      ]
    }
  ]
}
```

- `start`: ID of the first question
- `next: null`: End of this branch (onboarding completes)
- `next: "qX"`: Continue to question with ID "qX"
- `type: "free_text"` (optional): Marks an answer as **free-text**, meaning the user can reply with arbitrary text which will be stored as their answer.

### Free-Text Question Example

To ask a question where the user should reply with free text (e.g., \"What is your current field of study?\"), configure the question to have a single answer with `type: "free_text"`:

```json
{
  "id": "q1",
  "text": "What is your current field of study?",
  "answers": [
    { "id": "text", "type": "free_text", "next": null }
  ]
}
```

In Talk, the bot will prompt the user to **reply with their answer in free text** (no A/B/C selection needed). The stored onboarding context will contain the user's typed response.

## How Onboarding Context is Added to LLM

### Storage

User answers are stored in the `educai_chat_rooms` table:

| Column | Description |
|--------|-------------|
| `onboarding_status` | `mode_selection`, `questions`, or `completed` |
| `response_mode` | `mention` or `always` |
| `current_question_id` | ID of the question being asked (during onboarding) |
| `onboarding_answers` | JSON array of Q&A pairs |

### Answer Format

```json
[
  {
    "question_id": "q1",
    "question_text": "What is your main use case?",
    "answer_id": "a",
    "answer_text": "Learning"
  },
  {
    "question_id": "q2a",
    "question_text": "What subject interests you most?",
    "answer_id": "b",
    "answer_text": "Science"
  }
]
```

### System Prompt Injection

When the bot processes a message, the onboarding context is **appended to the system prompt**:

```
[Original System Prompt]

## User Onboarding Context
The user has provided the following information during onboarding:
- **What is your main use case?** → Learning
- **What subject interests you most?** → Science

Use this context to personalize your responses.
```

This happens in `BotService::processMessage()`:

```php
// Build onboarding context if available
$onboardingContext = '';
if ($room !== null) {
    $onboardingContext = $this->onboardingService->buildOnboardingContext($room);
}

// Inject into system prompt
$effectiveSystemPrompt = $bot->getSystemPrompt() . $onboardingContext;
```

### Why Inject into the System Prompt?

1. **Persistent Context**: Unlike conversation history, system prompt context is always present
2. **Token Efficiency**: Avoids duplicating context in every conversation turn
3. **Priority**: LLMs typically weight system prompt instructions heavily
4. **Separation**: Keeps user preferences separate from conversation flow

## Reset Command

Users can reset a chat room's state at any time:

```
((RESET))           → Resets all bots in the room
((RESET)) @mybot    → Resets only the specified bot
```

Reset performs:
1. Deletes the room state from `educai_chat_rooms`
2. Deletes conversation history from `educai_conversations`
3. Next bot mention triggers fresh onboarding

## Database Schema

### `educai_chat_rooms` Table

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Primary key |
| `bot_id` | BIGINT | Foreign key to `educai_bots` |
| `room_token` | VARCHAR(255) | Nextcloud Talk room identifier |
| `response_mode` | VARCHAR(16) | `mention` or `always` |
| `onboarding_status` | VARCHAR(32) | `mode_selection`, `questions`, `completed` |
| `current_question_id` | VARCHAR(64) | Current question ID (nullable) |
| `onboarding_answers` | TEXT | JSON array of answers |
| `activated_by` | VARCHAR(64) | User who first activated the bot |
| `created_at` | BIGINT | Unix timestamp |
| `updated_at` | BIGINT | Unix timestamp |

### `educai_bots` Table (New Column)

| Column | Type | Description |
|--------|------|-------------|
| `onboarding_questions` | TEXT | JSON question tree (nullable) |

## Key Files

| File | Purpose |
|------|---------|
| `lib/Db/ChatRoom.php` | Entity for room state |
| `lib/Db/ChatRoomMapper.php` | Database operations for room state |
| `lib/Service/OnboardingService.php` | Onboarding flow logic |
| `lib/Webhook/TalkHandler.php` | Message routing and onboarding detection |
| `lib/Service/BotService.php` | LLM interaction with context injection |
| `src/components/BotForm.vue` | UI for configuring onboarding questions |

## Message Routing Logic

```
Message Received
       │
       ▼
Is "((RESET))" command? ──Yes──► Handle reset
       │
       No
       ▼
Is bot @mentioned? ──Yes──► Get room state
       │                          │
       No                         ├── No state? ──► Start onboarding
       │                          │
       ▼                          ├── In onboarding? ──► Handle response
       │                          │
Check for onboarding-             └── Completed? ──► Process message
in-progress bots                           │
       │                                   │
       ├── Found? ──► Handle response      ├── Mention mode ──► Process
       │                                   │
       No                                  └── Always mode ──► Process
       ▼
Check for always-mode bots
       │
       ├── Found? ──► Process message
       │
       No
       ▼
Ignore message
```

## Best Practices

1. **Keep questions concise**: Users are answering in a chat interface
2. **Limit branching depth**: Max 2-3 levels to avoid confusion
3. **Use clear answer options**: "A" and "B" are simple to type
4. **Provide context in system prompt**: Reference how to use onboarding answers
5. **Test the flow**: Use `((RESET))` to test different answer paths

