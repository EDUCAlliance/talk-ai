# Document conversion with Docling

Open **Administration settings → Talk AI → Document Conversion**. Choose the
API profile that your server implements, then configure its endpoint and
authentication. **Test PDF conversion** uploads a small generated PDF through
the same client used for bot files, room documents and PDF URLs. Save the
settings after a successful test.

## Docling Serve

Choose **Docling Serve (official)** for `docling-project/docling-serve`, including
the `hwdsl2/docker-docling` packaging of that server.

- Enter the server base URL, such as `https://docling.example.com`, or its full
  `/v1/convert/file` endpoint. Reverse-proxy prefixes are retained, for example
  `https://example.com/docling/v1/convert/file`.
- Select **X-Api-Key** if server key authentication is enabled and enter the
  dedicated Docling key. Select **No authentication** for a server without key
  authentication. **Bearer** is available for gateways that require it.
- Do not use `/v1/convert/source`: that endpoint expects a JSON source
  description, not a multipart file upload. Async endpoints are not supported.

Talk AI sends the file in the `files` field with `to_formats=md` and
`target_type=inbody`. It reads Markdown from `document.md_content` and requires
a successful conversion status. Partial, failed or empty conversions do not
enter the index as successful documents.

The official profile never falls back to the main LLM API key. No-auth mode
sends neither an Authorization header nor X-Api-Key, even when a key is stored.
Changing profiles does not rewrite a custom endpoint or delete a stored key;
check both before testing or saving.

## AcademicCloud / EDUC compatibility

Existing installations keep **AcademicCloud / EDUC (compatibility)** with
**Bearer** authentication after upgrade. Their endpoint and encrypted keys are
unchanged. A blank endpoint retains the AcademicCloud default:
`https://chat-ai.academiccloud.de/v1/documents/convert`.

This profile sends the `document` upload field and reads the top-level
`markdown` response. With Bearer authentication, an unset dedicated Docling key
continues to use the main API key. X-Api-Key and no-auth modes are also available;
X-Api-Key requires a dedicated key. EDUC's informational warnings about its
lightweight extraction pipeline are logged without rejecting otherwise
successful legacy responses.

New installations default to the official profile with X-Api-Key selected;
conversion stays disabled until configured. The profiles select an HTTP
contract, not a guarantee about the server's extraction quality.

## Automatic detection

`POST /api/v1/admin/docling/detect` (administrators only) accepts an unsaved
`doclingApiEndpoint` and optional `doclingApiKey`; a blank key uses the stored
Docling key unless `ignoreStoredKey` is true, which the settings page sends
while the stored key is marked for removal. It never changes settings:

1. A blank URL selects the AcademicCloud default (legacy, Bearer). A URL ending
   in `/v1/convert/file` or `/v1/convert` is Docling Serve; one ending in
   `/v1/documents/convert` is the legacy profile.
2. Otherwise the server base (without a trailing `/v1`, `/docs` or
   `/openapi.json`) is asked for `/openapi.json`, then for Docling Serve's
   `/version`. Metadata requests time out after 10 seconds, follow no redirects
   and repeat an authentication rejection once with X-Api-Key and once with
   Bearer when a key is available. An unreachable server is not probed further.
3. If both are inconclusive, one Docling Serve test conversion is sent. The
   legacy route is tried only if that answer was HTTP 404 or 405, never after a
   timeout, 5xx or authentication failure.

Docling Serve uses X-Api-Key when a key is available (otherwise no
authentication) and retries an authentication rejection once with Bearer. The
legacy profile uses Bearer, falling back to the main API key like the saved
configuration. The result is proven by the same small PDF conversion as the
test button. The response is HTTP 200 whenever detection ran, including a
detected server whose conversion failed, and contains `success`, `profile`,
`auth_mode`, `service`, `title`, `version`, `source`, the `endpoint` to store,
the `conversion_url` without its query string, `duration_ms` and a fixed
`error` text. Invalid input returns HTTP 400. Upstream bodies and keys are never
returned; remote titles and versions are stripped of control characters and
limited to 80 characters.

In the settings page, **Detect and test** fills in the detected profile and
authentication as unsaved changes. The manual profile and authentication fields
remain available under **Set profile manually**. Diagnostics are translated
when a translation exists.

## Errors and long documents

The PDF test distinguishes authentication errors, an incorrect endpoint,
invalid upload requests, incomplete output and timeouts. A successful health
check alone is not a successful conversion.

Each client conversion call makes one HTTP submission. Talk AI does not resubmit after a timeout,
connection loss or 5xx response: the server may still be processing the first
upload. Docling Serve's synchronous wait limit is controlled by the server;
increasing the client timeout does not extend it. Check the server before
manually retrying. An explicit retry or later job execution is a new call, not
an exactly-once guarantee. Durable async submission and polling are outside this change.

If a document conversion fails while indexing a folder, Talk AI reports the
source as failed and retains its previous index rather than replacing it with
an incomplete one.

HTTP redirects are not followed. Configure the final conversion URL. For a
private-network service, Nextcloud's outbound-request policy must also permit
the target; this client does not bypass that policy.
