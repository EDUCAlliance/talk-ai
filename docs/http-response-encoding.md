# HTTP response encoding policy

Talk AI requests **uncompressed HTTP responses** (`Accept-Encoding: identity`)
and disables automatic content decoding for every app-owned HTTP request.
This mitigates the native Brotli decoder path relevant to CVE-2026-111497 without
depending on a particular system cURL/Brotli package version.

## Scope and implementation

`IdentityHttpClient` wraps Nextcloud's `IClientService` locally to this app.
LLM chat/model discovery (including streaming), embeddings, URL knowledge
sources, attachments, Docling, MCP, speech, vision, Catalogue and Talk API calls
all use this boundary. It does **not** replace Nextcloud's global HTTP client.
New app-owned HTTP callsites must also use `IdentityHttpClient::fromService()`;
do not call `newClient()` directly or add an independent transport.

The boundary enforces both negotiation and `decode_content: false`. Merely
removing `br` from the request is insufficient: an upstream can disregard
negotiation and send Brotli anyway. If a caller supplies raw cURL options, the
wrapper also disables native HTTP content decoding in those options. It does
not introduce a cURL option array into other requests, preserving Nextcloud's
transport defaults. HTTP **transfer** decoding (e.g. chunked framing) stays on.

An `on_headers` guard rejects any nonempty `Content-Encoding` other than
`identity`, before consumer callbacks/body processing, including redirect and
HTTP-error responses. A final response-header check also covers injected
clients that omit the callback. The guard never reads, rewinds or buffers a
response body; successful JSON, binary and SSE responses retain their existing
transport behavior. TLS verification, authentication, proxy configuration,
Nextcloud's address validation, cookies, timeouts and redirect options are not
relaxed by the wrapper.

Guard exceptions are unwrapped from Guzzle transport errors. LLM requests treat
them as terminal: no compatibility retry, stream-to-sync retry or model fallback
is attempted. There is no fallback that re-enables a decoder.

## Compatibility and deployment

A provider or reverse proxy must honor `Accept-Encoding: identity`. An upstream
that sends `br`, `gzip`, `deflate`, an unknown encoding or stacked encodings
despite that request is deliberately rejected. Configure that endpoint/proxy to
return uncompressed responses; do not add a decoder fallback. This policy can
increase transferred bytes, but does not disable file uploads, multipart
requests, SSE or binary files. It concerns HTTP `Content-Encoding`, not the
format of a downloaded document or an uploaded archive.

This is an **app-scoped mitigation, not a system Brotli upgrade**. It does not
protect other Nextcloud apps, the core's independent outbound requests, HPB,
recorders, browsers, Node.js services or external document converters. Those
runtimes still require their own inventory and vendor security updates. No
claim about the patch state or exploitability of a running deployment follows
from installing this app change alone.

## Verification

Unit tests cover policy overrides, callback ordering, compressed-response
rejection, exception preservation and single-attempt LLM failures. The
[real-transport smoke](../tests/integration/README.md#http-content-decoding-boundary)
uses Nextcloud's real HTTP client and bundled Guzzle/cURL with benign loopback
fixtures. Run it on Nextcloud 30, 34 and 35, without an installed instance,
database, credentials or external provider access. The unit regressions run in
the existing validation workflow; the real-transport smoke is a separate check.

References: [libcurl content decoding](https://curl.se/libcurl/c/CURLOPT_ACCEPT_ENCODING.html),
[Guzzle decode_content](https://docs.guzzlephp.org/en/stable/request-options.html#decode-content),
[Ubuntu CVE tracker](https://ubuntu.com/security/CVE-2026-111497).
