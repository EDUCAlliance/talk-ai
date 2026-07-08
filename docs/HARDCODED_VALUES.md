# Hardcoded Values Reference

This document provides a comprehensive overview of all hardcoded values in the EducAI Nextcloud app. These values include API defaults, model names, user-facing messages, and system parameters.

---

## Table of Contents

1. [API & Model Defaults](#api--model-defaults)
2. [Token Limits & Context Configuration](#token-limits--context-configuration)
3. [Agent Executor Parameters](#agent-executor-parameters)
4. [Rate Limiting Defaults](#rate-limiting-defaults)
5. [RAG Configuration Defaults](#rag-configuration-defaults)
6. [User-Facing Messages](#user-facing-messages)
7. [Tool Names & Descriptions](#tool-names--descriptions)
8. [System Prompt Injections](#system-prompt-injections)
9. [Frontend Placeholders & Labels](#frontend-placeholders--labels)
10. [Timing & Polling Intervals](#timing--polling-intervals)
11. [URL & API Endpoints](#url--api-endpoints)

---

## API & Model Defaults

### Database Entity Defaults (`lib/Db/Settings.php`)

| Property | Default Value | Line |
|----------|---------------|------|
| `apiProvider` | `'custom'` | 75 |
| `defaultModel` | `'llama-3.3-70b-instruct'` | 78 |
| `catalogueReindexHours` | `24` | 97 |
| `rateLimitSecond` | `3` | 103 |
| `rateLimitHour` | `500` | 104 |
| `rateLimitDay` | `1000` | 105 |
| `conversationContextTokens` | `8000` | 107 |

### LLM Client Defaults (`lib/Service/LLMClient.php`)

| Value | Description | Usage |
|-------|-------------|-------|
| `/v1/chat/completions` | API endpoint path suffix | Appended to base URL |
| `/v1/models` | Models listing endpoint | For fetching available models |

**Supported Providers:**
- `gwdg`: AcademicCloud (GWDG) SAIA - `https://chat-ai.academiccloud.de/v1/chat/completions`
- `custom`: Any compatible endpoint (user-configured)

### Bot Entity Defaults (`lib/Db/Bot.php`)

| Property | Default Value | Line |
|----------|---------------|------|
| `isActive` | `true` | 72 |
| `isPublic` | `false` | 73 |
| `visibility` | `'groups'` | 74 |
| `ragEnabled` | `false` | 77 |
| `approvalStatus` | `'approved'` | 85 |

---

## Token Limits & Context Configuration

### BotService (`lib/Service/BotService.php`)

| Parameter | Default Value | Description |
|-----------|---------------|-------------|
| `tokenLimit` | `8000` | Max tokens for conversation context |
| `historyLimit` | `50` | Max messages to fetch from conversation history |
| Token estimation | `~4 characters = 1 token` | Heuristic for token counting |

**Code Reference:**
```php
// Line 1683
$tokenLimit = $this->settingsService->getSettings()->getConversationContextTokens() ?? 8000;

// Line ~630
$history = $this->conversationMapper->findByBotAndRoom($bot->getId(), $roomToken, 50);
```

---

## Agent Executor Parameters

### Default Values (`lib/Service/AgentExecutor.php`)

| Parameter | Default Value | Line | Description |
|-----------|---------------|------|-------------|
| `max_iterations` | `10` | ~95 | Max tool-calling loop iterations |
| `temperature` | `0.2` | ~102 | LLM temperature for tool calls |
| `max_tokens` | `800` | ~103 | Max tokens per LLM response |
| Output truncation | `4000` chars | ~260 | Max chars in tool output |
| Loop detection threshold | `3` repetitions | ~436-446 | Detect infinite loops |
| Streaming flush interval | `3.0` seconds | ~128 | Partial response buffer flush |
| Streaming min chars | `100` chars | ~129 | Min chars before flush |

---

## Rate Limiting Defaults

### Default Rate Limits (`lib/Db/Settings.php`, `lib/Service/RateLimitService.php`)

| Limit | Default Value | Description |
|-------|---------------|-------------|
| Per Second | `3` | Requests per second |
| Per Hour | `500` | Requests per hour |
| Per Day | `1000` | Requests per day |

**Queue Message Placeholder Variables:**
- `{position}` - Queue position
- `{wait}` - Estimated wait time in seconds

**Default Queue Message:**
```
⏳ Your request has been queued and will be processed shortly...
Position: {position}, estimated wait: ~{wait} seconds.
```

---

## RAG Configuration Defaults

### Chunk Processing (`lib/Service/RagIngestionService.php`)

| Parameter | Default Value | Description |
|-----------|---------------|-------------|
| Chunk size | `750` tokens | Text chunk size for embeddings |
| Chunk overlap | `50` tokens | Overlap between chunks |

### Search Parameters (`lib/Service/BuiltInToolProvider.php`)

| Parameter | Default Value | Description |
|-----------|---------------|-------------|
| `limit` | `5` | Max search results returned |
| `min_score` | `0.3` | Minimum similarity score threshold |
| `top_k` | Configurable | Top K results to consider |

---

## User-Facing Messages

### Error Messages

#### BotService (`lib/Service/BotService.php`)

| Message | Context |
|---------|---------|
| `"I was unable to generate a response."` | Fallback when LLM fails |
| `"Sorry, I'm having trouble connecting to the AI service right now. Please try again in a moment."` | Connection error |

#### AgentExecutor (`lib/Service/AgentExecutor.php`)

| Message | Context |
|---------|---------|
| `"Ich konnte keine passende Antwort finden. Bitte versuchen Sie es mit einer anderen Frage."` | German fallback |
| `"I couldn't find a suitable answer. Please try asking a different question."` | English fallback |
| `"Processing took too long. Please try a simpler question."` | Timeout |
| `"I encountered an issue while processing. Please try again."` | Generic error |

#### TalkHandler (`lib/Webhook/TalkHandler.php`)

| Message | Context |
|---------|---------|
| `"🤔 _(The AI is thinking... this may take a moment)_"` | Thinking placeholder during streaming |
| `"_(The AI finished thinking but produced no response. Please try again.)_"` | Empty response after thinking |
| `"_(No response received from AI. Please try again.)_"` | Empty response |
| `"Chat with **{botName}** has been reset. Mention the bot again to start fresh."` | Reset confirmation |
| `"Failed to reset chat. Please try again."` | Reset failure |
| `"Chat has been reset. Mention a bot to start fresh."` | Generic reset |
| `"No active bots found in this chat. Mention a bot to activate it."` | No bots found |

### Onboarding Messages (`lib/Service/OnboardingService.php`)

**Welcome Message:**
```
You have activated the bot **{mentionName}**.

Would you like me to respond to:
- **A**: Only when you @mention me
- **B**: Every message in this chat

Reply with **A** or **B**.

_(You can reset this chat anytime with `((RESET))`)_
```

**Completion Message:**
```
Thanks! I've noted your preferences. I'm ready to help!

_(Reminder: You can reset this chat with `((RESET))`)_
```

**Mode Selection Confirmation:**
```
Got it! I'll respond to {modeDescription}.

I'm ready to help! You can reset this conversation anytime with `((RESET))`.
```

**Invalid Response:**
```
Please reply with **A** (only when @mentioned) or **B** (every message).
```

---

## Tool Names & Descriptions

### Built-in Tool Identifiers (`lib/Service/BuiltInToolProvider.php`)

| Constant | Value |
|----------|-------|
| `TOOL_CATALOGUE_SEARCH` | `'catalogue_search'` |
| `TOOL_RAG_SEARCH` | `'rag_search_documents'` |

### Tool Descriptions (LLM-facing)

**Catalogue Search:**
```
(example) Search a course catalogue to find learning opportunities...
Returns: title, type, provider, link.
IMPORTANT: When presenting results, include the link for each result.
```

**RAG Document Search:**
```
Search through the bot's attached knowledge base documents to find relevant information.
Use this tool when the user asks questions that might be answered by the bot's document collection.
Returns relevant text snippets with source file information.
```

---

## System Prompt Injections

### RAG Context Injection (`lib/Service/BotService.php`)

```
## Knowledge Base Context
The following information was retrieved from your attached documents and may help answer the user's question:

{retrieved_content}

Use this context when relevant to the user's question. If the context doesn't contain relevant information, you may still use your general knowledge to help.
```

### Tool Calling Instructions (`lib/Service/AgentExecutor.php`)

```
You have access to the following tools. When you need to use a tool, respond with a JSON object in this exact format:
{"tool": "tool_name", "arguments": {"param1": "value1"}}

Available tools:
{tool_descriptions}

Important instructions:
- Only use ONE tool at a time
- Wait for the tool result before using another tool
- If a tool returns an error, explain what happened and try a different approach
- When you have enough information to answer, provide your final response without using any tools
```

### Onboarding Context (`lib/Service/OnboardingService.php`)

```
## User Onboarding Context
The user has provided the following information during onboarding:
- **{question_text}** → {answer_text}

Use this context to personalize your responses.
```

---

## Frontend Placeholders & Labels

### AdminSettings.vue

| Field | Placeholder | Description |
|-------|-------------|-------------|
| API Endpoint | `https://chat-ai.academiccloud.de/v1/chat/completions` | AcademicCloud (GWDG) SAIA URL |
| API Key | `sk-...` | API key format hint |
| Default Model | `llama-3.3-70b-instruct` | Model name |
| Embedding Endpoint | `https://chat-ai.academiccloud.de/v1/embeddings` | Embeddings URL |
| Embedding Model | `multilingual-e5-large-instruct` | Model identifier |
| Docling Endpoint | `https://chat-ai.academiccloud.de/v1/documents/convert` | Default Docling URL |
| Catalogue Endpoint | `https://catalogue.example.com/api` | Catalogue API URL |
| Chunk Size | `750` | RAG chunk size |
| Chunk Overlap | `50` | RAG overlap |
| Rate Limit Second | `3` | Per-second limit |
| Rate Limit Hour | `500` | Per-hour limit |
| Rate Limit Day | `1000` | Per-day limit |
| Context Tokens | `8000` | Token limit |
| Queue Message | `⏳ Your request has been queued. Position: {position}, estimated wait: ~{wait} seconds.` | Default queue message |

### BotForm.vue

| Field | Placeholder |
|-------|-------------|
| Bot Name | `Support Helper` |
| Mention Name | `supportbot` |
| Description | `A helpful assistant that answers questions about...` |
| System Prompt | `You are a helpful support assistant. Your role is to...` |
| URL Input | `https://example.com/document.pdf` |

### Progress Stage Labels (`BotForm.vue`)

```javascript
const stages = {
    collecting: 'Collecting files…',
    extracting: 'Extracting text…',
    chunking: 'Processing content…',
    embedding: 'Generating embeddings…',
    storing: 'Saving results…',
    ready: 'Complete',
}
```

---

## Timing & Polling Intervals

| Location | Value | Description |
|----------|-------|-------------|
| `BotForm.vue` | `2000` ms | RAG source progress polling |
| `TalkHandler.php` | `10` seconds | HTTP request timeout to Talk API |
| `AgentExecutor.php` | `3.0` seconds | Streaming flush interval |
| Catalogue reindex | `24` hours | Default reindex interval |

---

## URL & API Endpoints

### AcademicCloud (GWDG) SAIA Endpoints

| Endpoint | Description |
|----------|-------------|
| `https://chat-ai.academiccloud.de/v1/chat/completions` | Chat completion |
| `https://chat-ai.academiccloud.de/v1/embeddings` | Embeddings |
| `https://chat-ai.academiccloud.de/v1/models` | Model listing |
| `https://chat-ai.academiccloud.de/v1/documents/convert` | Docling document conversion |

### API Route Patterns (Relative)

| Pattern | Description |
|---------|-------------|
| `/v1/chat/completions` | Chat completion endpoint |
| `/v1/embeddings` | Embeddings endpoint |
| `/v1/models` | Model listing |
| `/v1/documents/convert` | Docling document conversion |

### OCS API Endpoints (Talk Bot)

```php
$endpoint = $baseUrl . 'ocs/v2.php/apps/spreed/api/v1/bot/' . $roomToken . '/message';
```

---

## Search Keywords for Automatic Tool Selection

### BotService (`lib/Service/BotService.php`)

Keywords that trigger automatic RAG search:
```php
$searchKeywords = ['search', 'find', 'look up', 'document', 'file', 'information about'];
```

---

## Visibility Options

### BotForm.vue

| Value | Label |
|-------|-------|
| `personal` | Just for me (personal) |
| `global` | Global (requires approval for non-admins) |
| `groups` | Specific groups |
| `teams` | Specific teams |

---

## Constants Summary

### Quick Reference Table

| Category | Value | Location |
|----------|-------|----------|
| Default Model | `llama-3.3-70b-instruct` | Settings entity |
| API Provider | `custom` (configurable) | Settings entity |
| Token Limit | `8000` | Settings entity |
| History Limit | `50` messages | BotService |
| Max Iterations | `10` | AgentExecutor |
| Temperature | `0.2` | AgentExecutor |
| Max Tokens | `800` | AgentExecutor |
| Output Truncation | `4000` chars | AgentExecutor |
| RAG Chunk Size | `750` tokens | Default |
| RAG Overlap | `50` tokens | Default |
| RAG Limit | `5` results | BuiltInToolProvider |
| RAG Min Score | `0.3` | BuiltInToolProvider |
| Rate Limit/sec | `3` | Settings entity |
| Rate Limit/hour | `500` | Settings entity |
| Rate Limit/day | `1000` | Settings entity |
| Reindex Interval | `24` hours | Settings entity |

---

## Notes

1. **Configurable vs Hardcoded**: Many of these values are defaults that can be overridden through admin settings. The hardcoded values serve as fallbacks when no configuration is provided.

2. **Internationalization**: User-facing messages are primarily in English, with some German fallbacks in the AgentExecutor. Consider implementing proper i18n if multi-language support is needed.

3. **Token Estimation**: The heuristic of ~4 characters = 1 token is an approximation. Actual token counts vary by model and tokenizer.

4. **API Compatibility**: The app is designed for the AcademicCloud (GWDG) SAIA API but works with any compatible endpoint. Default model is `llama-3.3-70b-instruct` from GWDG's available models.
