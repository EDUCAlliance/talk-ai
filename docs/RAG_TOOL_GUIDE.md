# RAG Tool

Talk AI exposes indexed documents to the model through a search tool instead of injecting a fixed number of chunks into every prompt. The LLM decides when to search, crafts its own queries, can search multiple times per turn, and filters results by relevance — so document context only enters the conversation when it's actually needed.

## `rag_search_documents`

Semantic search over the bot's indexed documents. Available automatically when RAG is enabled for a bot that has at least one `ready` source.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `query` | string | yes | Search query |
| `limit` | integer | no | Max results (default 5, max 20) |
| `min_score` | number | no | Minimum similarity score, 0.0–1.0 (default 0.3) |

Typical flow: the user asks *"What Python version is required?"* → the model calls the tool with `{"query": "Python version required installation", "limit": 3}` → the tool returns matching chunks with source paths and relevance scores → the model synthesizes an answer, searching again with a different query if the first pass was incomplete.

## Configuration

1. **Enable RAG globally** — Admin settings → Talk AI → RAG & Embeddings.
2. **Configure the embedding API** — endpoint, key, and model (e.g. `text-embedding-3-large`).
3. **Enable RAG on the bot** when creating/editing it.
4. **Attach files or folders** as sources; indexing runs via background jobs ([RAG_BACKGROUND_JOBS_GUIDE.md](RAG_BACKGROUND_JOBS_GUIDE.md)).

Chunking is controlled by the admin settings **Chunk Size** (tokens per chunk) and **Chunk Overlap**. Smaller chunks (300–500 tokens) suit precise Q&A; larger ones (750–1000) suit narrative content.

## System Prompt Enhancement

When a bot has RAG enabled and indexed documents, its system prompt is automatically extended with search instructions: always search before answering questions that might be covered by the documents, convert user questions into search keywords, synthesize answers from the returned chunks, and say so when nothing was found. Add your own instructions to the bot's system prompt if the default search behavior isn't right for your use case.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "No documents have been indexed" | Attach sources and wait for the background job (`pending` → `ready`) |
| "No matching document chunks found" | Different query, lower `min_score`, higher `limit`; verify the content is actually in the documents |
| "File or folder no longer exists" | Source was deleted; embeddings are cleaned up automatically — remove the source |
| Tool not offered to the bot | RAG must be enabled globally *and* on the bot, with ≥ 1 `ready` source |

Tool calls are logged to `nextcloud.log` (`EducAI:` lines) but not persisted in `educai_conversations`.

## Technical Details

- Cosine similarity between the query embedding and chunk embeddings, computed in PHP (database-portable; vectors stored as JSON arrays).
- Results are Markdown-formatted with source attribution and relevance scores, capped at 20 chunks per search.
- Descriptive filenames help — they appear in the results and give the model context.
- Upgrading from older Top-K versions needs no action: the `ragTopK` / `ragMaxContextTokens` settings are simply no longer used.
