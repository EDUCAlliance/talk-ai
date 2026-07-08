# Pseudo Streaming (Chunked Replies) in Talk AI

This document explains how Talk AI simulates “streaming” replies in Nextcloud Talk by emitting partial chunks of the assistant response while the LLM stream is still in progress.

## High-level idea

- The LLM API is called with `stream=true` (SSE / chunked HTTP response).
- Incoming token deltas are buffered on the server.
- The buffer is flushed periodically (paragraph / sentence boundaries) as **separate Talk messages**.
- Only the **first** chunk is sent as a reply to the original user message (`replyTo=<messageId>`). All subsequent chunks are sent without `replyTo` so they don’t all appear as nested replies.

## Where this is implemented

- `lib/Service/LLMClient.php::streamChatCompletion()`
  - Opens the streaming connection to the OpenAI-compatible endpoint and calls a callback for each `delta` chunk (`delta.content`, and optionally `delta.tool_calls`).
- `lib/Service/BotService.php::processMessage()`
  - When **no tools** are used, it converts streaming `delta.content` into readable chunks via an `onProgress(string $partial)` callback.
- `lib/Service/AgentExecutor.php::run()`
  - When **tools are enabled**, it also streams via `LLMClient::streamChatCompletion()` and uses the same buffering/flush strategy.
  - Additionally, it can emit small progress messages (e.g. “🔧 _Using tool(s): …_”) via the streaming callback.
- `lib/Webhook/TalkHandler.php::processNormalMessage()`
  - Wires `onProgress(...)` to Talk by calling `sendReplyToTalk(...)` for every emitted chunk.
  - Manages `replyTo` behavior (only first chunk replies to the user message).
  - Filters model “thinking tokens” and optionally sends a placeholder while the model is thinking.

## Chunking / flush strategy (server-side)

Both `BotService` (no-tools path) and `AgentExecutor` (tools path) implement the same strategy:

1. Append each incoming `delta['content']` to a `$buffer`.
2. Flush when:
   - a paragraph boundary is detected (`\n\n`), **or**
   - enough time passed (3 seconds) and enough text accumulated (>100 chars) → then flush at the next sentence boundary (best-effort).
3. After the stream ends, flush any remaining buffer.

This avoids token-by-token “spam” and produces readable chunks.

## Delivery to Talk + `replyTo` behavior

In `TalkHandler::processNormalMessage()`, the streaming callback:

- filters out “thinking tokens” (for models that emit them),
- sends each non-empty chunk to Talk via `sendReplyToTalk($roomToken, $chunk, $replyTo)`,
- uses `replyTo=<original message id>` **only for the first streamed chunk**, then switches to `replyTo=0` for all later chunks.

This prevents Talk from rendering every chunk as a reply to the original user message.

Also note:

- If **no** chunks were streamed (`$alreadySent === false`), the handler sends a single final message (normal non-streamed behavior).
- If chunks **were** streamed, the handler skips sending the final full response to avoid duplicate content.

## Why this is “pseudo” streaming

- Nextcloud Talk bot messages are sent as discrete messages (no server-side “edit this message” API).
- The app therefore simulates streaming by sending multiple messages as the response arrives.
- Chunking happens at the PHP application level; Talk clients are not consuming the LLM stream directly.

## Troubleshooting

- **No streaming visible**:
  - Verify your LLM provider supports `stream=true`.
  - Check `nextcloud.log` for `EducAI:` entries (from `LLMClient`, `AgentExecutor`, `TalkHandler`).
- **Too many tiny chunks**:
  - Adjust the flush strategy in `BotService` / `AgentExecutor` (paragraph vs time-based flush thresholds).

