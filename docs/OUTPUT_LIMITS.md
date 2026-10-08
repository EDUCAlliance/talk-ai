# Model output limits

## Configuration

Open **Administration → Talk AI → Response Limits**. The default output budget is
**4096 tokens per model response**, including intermediate model turns in an agent
run. It does not change the conversation-history budget or the existing turn,
tool-call, provider-attempt, and execution-time limits.

Optional overrides use exact, case-sensitive model references, such as
`primary:my-chat-model` or `secondary:my-reasoning-model`. The selected provider
route determines the override, including when the existing fallback mechanism
selects another model. Removing an override restores the global value for that
model. This is an administrator setting, not a bot-owner override.

Values must be integers from 1 to 131072. That range is an application validation
bound, **not** a guarantee of model support. Choose a value your provider accepts.
A higher ceiling may increase latency and cost. Reasoning models can spend part
of their completion budget on internal reasoning, leaving less for visible text.
Talk AI retains its existing `max_tokens` / `max_completion_tokens` compatibility
handling; it does not infer a model's maximum context or output capacity.

The settings API uses `maxOutputTokens` and `modelOutputTokenLimits`:

```json
{
  "maxOutputTokens": 4096,
  "modelOutputTokenLimits": {
    "secondary:my-reasoning-model": 16384
  }
}
```

Omitting these fields preserves saved values; an empty override map clears it.
Upgrade migration adds the new settings without replacing credentials, models,
conversation-history limits, or other existing settings.

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
route resolution, streaming/non-streaming `length`, empty and sanitized output,
truncated tool calls, previously completed mutations, queue status propagation,
Talk delivery, localized notices, and trace redaction.

For a local browser acceptance check, use a synthetic bot and room. Save/reload
the budget in the admin UI, send a long message through the Talk composer with
4096 tokens, then repeat at a smaller limit. Confirm the marker in Talk and
conversation history, the Incomplete activity filter and JSON export, and a
single provider request. Restore the desired budget after testing. Use controlled
provider fixtures for empty output and replies above Talk's character limit.
