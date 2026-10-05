# Talk webhook size limits

Talk AI accepts a maximum **1 MiB (1,048,576 bytes)** per incoming webhook body.
The limit is enforced on the actual stream, including when Content-Length is
absent or inaccurate, and before Talk AI verifies the signature or decodes JSON.
An oversized body returns HTTP 413. Missing/invalid authentication returns HTTP
401; malformed JSON and invalid payload shapes return HTTP 400. Operational
failures after acceptance retain the existing HTTP 200 acknowledgement to avoid
re-running the bot/provider because of webhook retries.

This is a transport limit, **not** the configured LLM conversation token budget.
Talk ordinarily sends the current message and, for a reply, one quoted parent;
it does not include the entire accumulated chat history. Talk permits up to
32,000 Unicode characters per message. The 1 MiB envelope accommodates two
maximum-size emoji messages with both JSON-encoding layers and ordinary metadata.

The application limit cannot bound memory already used by Nextcloud, another
installed app, or a reverse proxy before the controller runs. For a pre-PHP
boundary, configure the web server or reverse proxy to enforce the same limit
specifically on `/apps/educai/webhook/talk` (and the equivalent `index.php` URL).
Do not apply this small limit globally to Nextcloud file uploads.

## Model context is a separate limit

Talk AI loads up to 50 stored conversation messages and estimates their tokens as
one token per four Unicode characters. The configured conversation-context budget
selects history; it deliberately retains the newest user message even when that
message alone exceeds the budget. System prompts, tool schemas and agent-loop
observations add further context. Consequently this setting does **not** guarantee
that a request fits a model's actual context window.

An explicit provider context-limit error now stops the request without retrying
the same input through parameter-compatibility or fallback paths. The user receives
a specific suggestion to shorten the request, reduce history or use a larger-context
model. Completed tools are not replayed, and the error is not stored as assistant
history. There is no automatic summarization or silent truncation of the current
user message; exact model-aware token budgeting remains a separate feature.

## Long replies

Replies over 32,000 Unicode characters are sent as multiple individually signed
Talk messages. Short replies keep their existing delivery path. Chunk references
are stable across retries of the same queued delivery, but Talk itself does not
deduplicate references. Talk AI checks committed comments by room, bot identity,
reference and content before resending a chunk. It stops after an unconfirmed
transport timeout instead of blindly duplicating a possibly accepted chunk; the
trace records partial/unconfirmed delivery.

This is not an atomic exactly-once delivery protocol. Talk also trims each message
and rejects whitespace-only messages, so chunk-edge whitespace and Markdown
formatting spanning messages are not guaranteed to render identically to one
unlimited message. Non-whitespace text is not intentionally dropped.

## Wiki tool pagination

The wiki page's serialized tool observation must fit 4,000 Unicode characters.
JSON escaping and metadata count toward that limit. Oversized observations now
shorten the page content **before** encoding and update `returned_length`,
`next_offset` and `has_more` to match the delivered text. This avoids invalid JSON
and skipped text when paging through quote-, backslash- or control-heavy files.
Metadata that leaves no room for progress returns a tool error rather than an
unchanged pagination cursor. Other tools retain their existing per-result text
limit; no aggregate model-context budget is implied.
