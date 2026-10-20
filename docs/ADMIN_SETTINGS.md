# Administrator settings

**Administration settings → Talk AI** is organised in six tabs. Every tab can be opened directly with `?tab=<name>`, for example `/settings/admin/educai?tab=knowledge`.

| Tab | Contents | Saving |
| --- | --- | --- |
| Overview | Items that need attention and one status card per area. A new installation sees a four-step setup checklist instead. | – |
| Models | Primary and secondary provider, default and fallback model, models released for bots, creativity, response length, conversation memory, exceptions per model, test request | Save bar |
| Knowledge | Knowledge sources (RAG), embeddings, index maintenance, document conversion (Docling), course catalogue | Save bar |
| Capabilities | Image understanding, speech to text, MCP tools | Save bar; MCP tools apply immediately |
| System | Talk connection (webhook secret and URL), rate limits and queue, time limits, app icon | Save bar |
| Bots | All bots with status filter and search | Applies immediately |

## Saving

A bar at the bottom appears as soon as a value differs from the saved state. It names the changed areas and offers **Save** and **Discard**, and tabs with unsaved changes carry a dot. The browser asks before leaving the page with unsaved changes. Bots, MCP tools, icon uploads, reindexing and queue processing are not part of the form and take effect immediately.

The saved payload is the same as before the redesign. The only addition is an optional `clearCredentials` list for removing keys explicitly (see below).

## Keys

Stored keys are never sent back to the browser. A stored key is shown as **Stored** with **Replace**, and optional keys also offer **Remove**. Leaving a field empty always keeps the stored key. A key is removed only when **Remove** was chosen and the settings are saved. The primary API key and the webhook secret can only be replaced.

Embeddings, image understanding and speech to text either use the primary provider (no own endpoint or key) or an own endpoint. Switching back to the primary provider clears the own endpoint and removes the separate key on save.

## Overview and checks

The overview is based on saved settings only. Unsaved edits do not change it.

Explicit tests of the model, Docling, image understanding, speech to text and the course catalogue are remembered on the server. The overview shows the last result together with whether it still matches the saved configuration. A test of unsaved values counts once exactly those values are saved. Only the success flag, time, duration, a short summary and the safe error text are stored, plus an HMAC of the tested configuration keyed with the instance secret. Keys and provider responses are never stored.

## Document conversion

Enter the server address and, if the server requires it, a key. **Detect and test** looks for the Docling Serve or the AcademicCloud/EDUC-compatible API, chooses the authentication and proves the result with one small PDF conversion. Docling Serve wins if a server offers both. A successful detection fills in profile and authentication as unsaved changes; a failed one leaves the form unchanged. Both can still be set manually. See [Docling setup](DOCLING.md) for the detection steps.

## Released models

With **Bots may choose a released model themselves** switched on, models are released per provider in a checklist with a filter. Released models are listed first. The list marks the default and fallback model, newly listed models (first listed within the last 14 days) and released models that the provider's current model list does not contain (**Not listed**). Newly listed models are never released automatically. Models the provider does not list can be added with their provider and model ID.
