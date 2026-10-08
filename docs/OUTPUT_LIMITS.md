# Model output limits

## Configuration

Open **Administration → Talk AI → Response Limits**. The default output budget is
**32768 tokens per model response**, including intermediate model turns in an agent
run. It does not change the conversation-history budget or the existing turn,
tool-call, provider-attempt, and execution-time limits.

Optional overrides use exact, case-sensitive model references, such as
`primary:my-chat-model` or `secondary:my-reasoning-model`. The selected provider
route determines the override, including when the existing fallback mechanism
selects another model. Removing an override restores the global value for that
model. This is an administrator setting, not a bot-owner override.

Values must be integers from 1 to 131072. That range is an application validation
bound, **not** a guarantee of model support. The ceiling permits longer output;
it does not ask the model to fill it. Use the bot prompt to request concise replies.
A higher ceiling can allow more latency and cost. Reasoning models share their
completion budget between internal reasoning and visible text. Talk AI retains its
existing `max_tokens` / `max_completion_tokens` compatibility handling.

The settings API uses `maxOutputTokens` and `modelOutputTokenLimits`:

```json
{
  "maxOutputTokens": 32768,
  "modelOutputTokenLimits": {
    "primary:small-chat-model": 8192,
    "secondary:my-reasoning-model": 65536
  }
}
```

Omitting these fields preserves saved values; an empty override map clears it.
Upgrade migration adds the new settings without replacing credentials, models,
conversation-history limits, or other existing settings.

## Provider capacities and context headroom

Loading or reloading models also saves optional capacities advertised by the
configured endpoints. For each actual request (including a fallback), Talk AI
uses the lower of the configured budget and that exact endpoint/model's reported
output capacity. A caller's explicit budget is constrained in the same way.

Recognized positive integer metadata fields in a model entry are
`max_output_tokens`, `max_completion_tokens`, and
`top_provider.max_completion_tokens` for output; `context_length`,
`max_model_len`, and `top_provider.context_length` for the whole context.
Where multiple capacities are supplied, the smaller applies. Invalid values are
ignored. These are optional extensions, not required OpenAI `/models` fields.

When context capacity is known, the budget also reserves space for **all** prepared
messages (including system prompt and tool results), tool schemas, and 1024 tokens
of framing headroom. Input and conversation history share a rough estimate of
one token per four Unicode characters, applied to the full serialized input for
output budgeting. This is not a provider tokenizer: it can overestimate or
underestimate usage, especially for non-English text and multimodal content.
When the estimate leaves no room, Talk AI sends the unchanged input with a minimal
one-token output allowance instead of declaring a context overflow locally. This
may produce an incomplete response; only a provider-confirmed overflow triggers
the existing context-limit error. No history is silently discarded and no
automatic whole-run replay is added.

Capacity data stays bound to the configured endpoints. Last-known capacities
remain usable after the five-minute **routing** cache TTL; reload models to refresh
them, including removing capacities the provider no longer reports. Changing an
endpoint invalidates that cache. Qualified chat requests never require an extra
model-list request just to calculate their budget.

**Unknown capacities:** there is no guessed model catalog or universal context
window. If the provider does not report limits (or models have not been loaded),
the configured budget is used. Set exact lower overrides for small models and
keep conversation memory appropriate to the provider's context. A provider can
still reject a request because its real limits or tokenization differ.

## When the model stops at its output limit

When the provider reports `finish_reason: "length"`:

- Only visible text from the final model turn without tool calls is eligible for
  delivery. The normal content sanitizer runs before delivery or persistence.
- Available text is followed by **Response incomplete: output limit reached.**
  Both text and notice are saved in the conversation history. The notice follows
  the initiating user's language where a translation is available.
- If no usable text remains (for example, the budget was consumed by reasoning),
  Talk receives a specific output-limit notice. No invented assistant response is
  added to the conversation history.
- Tool calls in the truncated turn are rejected, even if their arguments happen
  to look complete. Previously executed actions are not replayed.
- Recognized truncated legacy JSON/XML tool envelopes are not delivered as text
  when the corresponding compatibility profile is enabled. JSON recognition
  covers supported metadata and reversed field order; it does not reconstruct
  or execute an incomplete call or classify arbitrary unfinished JSON as a tool.
- The activity run is **Incomplete**, not Success or a connection error. This
  also applies to queued requests. Token budgets and numerical usage details
  remain visible in activity exports, while credential values remain redacted.

There is no automatic continuation, larger-budget retry, or whole-run retry on
`length`. Content-filter errors, explicit context overflow, transport failures,
and other agent budgets retain their separate handling. An actual Talk delivery
failure can still produce a partial-delivery or error status.

Long replies, including incomplete ones and their notice, use the existing
UTF-8-aware Talk splitting at 32000 characters. Existing whitespace trimming and
ambiguous-delivery limitations still apply; this change does not add exactly-once
delivery guarantees.

## Verification

Regression coverage includes settings validation and migration, actual provider
route resolution and capacity clamping, full-input context estimation, cold/stale
discovery caches, streaming/non-streaming `length`, empty and sanitized output,
truncated tool calls, previously completed mutations, queue status propagation,
Talk delivery, localized notices, and trace redaction.

For a local browser acceptance check, use a synthetic bot and room. Save/reload
the budget in the admin UI, send a long message through the Talk composer with
32768 tokens, then repeat at a smaller limit. Confirm the marker in Talk and
conversation history, the Incomplete activity filter and JSON export, and a
single provider request. Restore the desired budget after testing. Use controlled
provider fixtures for empty output and replies above Talk's character limit.
