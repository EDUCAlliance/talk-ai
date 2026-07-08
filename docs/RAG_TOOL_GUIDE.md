# RAG Tool Integration

The EducAI plugin provides a built-in RAG (Retrieval-Augmented Generation) tool that allows the AI assistant to dynamically search through indexed documents. Unlike the traditional Top-K approach that injects a fixed number of chunks into every query, the RAG tool gives the LLM control over when and how to search the knowledge base.

## How It Works

When RAG is enabled for a bot that has indexed documents:

1. **Tool Availability**: The `rag_search_documents` tool becomes automatically available to the LLM
2. **LLM Decision**: The LLM decides when to search based on the user's question
3. **Dynamic Queries**: The LLM can craft specific search queries and request the number of results it needs
4. **Multiple Searches**: The LLM can perform multiple searches in a single conversation turn
5. **Score Filtering**: The LLM can set minimum relevance thresholds to filter out poor matches

## Available Tool

### `rag_search_documents`

Search through the bot's indexed documents using semantic similarity.

**Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | Yes | Search query to find relevant document chunks |
| `limit` | integer | No | Max results to return (default: 5, max: 20) |
| `min_score` | number | No | Minimum similarity score threshold (0.0-1.0, default: 0.3) |

**Example usage by AI:**
```json
{
  "query": "Modul 3 Titel",
  "limit": 5,
  "min_score": 0.3
}
```

## Advantages Over Traditional Top-K

| Feature | Traditional Top-K | RAG Tool |
|---------|------------------|----------|
| Search Control | Fixed per query | LLM decides when to search |
| Query Specificity | Uses raw user message | LLM crafts optimal search query |
| Result Count | Fixed (e.g., always 5) | Dynamic based on question complexity |
| Multiple Searches | Not possible | LLM can search multiple times |
| Score Filtering | Not available | LLM can filter by relevance |
| Context Efficiency | Always injects chunks | Only when relevant |

## Configuration

### Prerequisites

1. **Enable RAG globally** in Admin Settings → EducAI → Retrieval-Augmented Generation
2. **Configure embedding API** endpoint and model
3. **Enable RAG on the bot** when creating/editing the bot
4. **Index documents** by attaching files or folders to the bot

### Admin Settings

The following settings affect RAG behavior:

| Setting | Description |
|---------|-------------|
| Enable RAG | Master switch for RAG functionality |
| Embedding API Endpoint | API endpoint for generating embeddings |
| Embedding API Key | Credentials for the embedding API |
| Embedding Model | Model used for text embeddings (e.g., `text-embedding-3-large`) |
| Chunk Size | Token count per document chunk (affects search granularity) |
| Chunk Overlap | Overlap between consecutive chunks (provides context continuity) |

**Note:** Top-K and Max Context Tokens settings have been removed as the LLM now controls these dynamically.

## Typical AI Workflow

1. User asks: *"What are the system requirements?"*
2. AI recognizes this might be in the documentation
3. AI calls `rag_search_documents` with:
   ```json
   {
     "query": "system requirements hardware software prerequisites",
     "limit": 5
   }
   ```
4. Tool returns relevant chunks with relevance scores
5. AI synthesizes an answer based on the retrieved content
6. If the answer is incomplete, AI may search again with a different query

## Example Conversation

**User:** What Python version is required?

**AI (internal):** I should search the knowledge base for Python version requirements.

**Tool Call:**
```json
{
  "name": "rag_search_documents",
  "arguments": {
    "query": "Python version required installation",
    "limit": 3,
    "min_score": 0.5
  }
}
```

**Tool Response:**
```
Found 3 relevant document chunk(s) for: "Python version required installation"

---
**Source 1:** /Documents/installation-guide.md (chunk 2) [relevance: 0.87]

## Prerequisites
- Python 3.9 or higher
- pip package manager
- virtualenv (recommended)

---
**Source 2:** /Documents/README.md (chunk 1) [relevance: 0.72]

This project requires Python 3.9+ and Node.js 18+.
...
```

**AI Response:** According to the documentation, Python 3.9 or higher is required. The installation guide also recommends using virtualenv for managing dependencies.

## System Prompt Enhancement

When a bot has RAG enabled and indexed documents, the system prompt is automatically enhanced with detailed instructions:

```
## CRITICAL: Document Search Instructions
You have access to a knowledge base with indexed documents. You MUST follow these rules:

1. **ALWAYS search first**: Before answering ANY question that might be in the documents, 
   call the `rag_search_documents` tool.
2. **Never guess**: Do NOT answer from memory if the answer could be in the documents. 
   Search first!
3. **How to search**: Convert the user's question into search keywords.
   - User asks: "Was ist der Titel von Modul 3?" → Search: "Modul 3 Titel"
   - User asks: "What are the requirements?" → Search: "requirements prerequisites"
4. **After searching**: Read the returned chunks carefully and synthesize an answer 
   based on the document content.
5. **If no results**: Tell the user you couldn't find that information in the documents.
6. **Never output raw JSON**: Use proper tool calls, not JSON text in your response.
```

## Troubleshooting

**"No documents have been indexed"**
- Attach files to the bot via the Sources section
- Wait for background job to process (check status: pending → ready)
- Ensure file types are supported (txt, md, pdf, docx, etc.)

**"No matching document chunks found"**
- Try different search queries
- Lower the `min_score` threshold
- Increase the `limit` parameter
- Check if documents contain the expected content

**"Error: No bot context available"**
- This is an internal error; ensure the bot ID is passed correctly
- Re-save the bot and try again

**"File or folder no longer exists"**
- The source file/folder was deleted from Nextcloud
- Embeddings are automatically cleaned up when this is detected
- You can safely remove the source from the bot

**RAG tool not appearing for bot**
- Verify RAG is enabled in Admin Settings (global toggle)
- Verify RAG is enabled on the specific bot
- Verify the bot has at least one indexed source with "ready" status

## Best Practices

1. **Index relevant documents**: Only attach documents that are actually useful for the bot's purpose
2. **Use descriptive filenames**: They're included in search results and help the LLM understand context
3. **Chunk size tuning**: Smaller chunks (300-500 tokens) for precise Q&A, larger (750-1000) for narrative content
4. **Monitor tool usage**: Check Nextcloud logs (`nextcloud.log`) for `EducAI:` tool execution lines (tool calls are not persisted in `educai_conversations`)
5. **System prompt guidance**: Add specific instructions about when to search if the default behavior isn't ideal

## Migration from Top-K

If you're upgrading from a version that used automatic Top-K injection:

1. **No action required**: The RAG tool is automatically available for RAG-enabled bots
2. **Settings removed**: `ragTopK` and `ragMaxContextTokens` settings are no longer used
3. **Behavior change**: RAG context is no longer automatically injected into every query
4. **Better efficiency**: The LLM now decides when to search, reducing unnecessary context

## Technical Details

- **Similarity Algorithm**: Cosine similarity between query and chunk embeddings
- **Embedding Storage**: Vectors stored as JSON arrays in database
- **Result Format**: Markdown-formatted text with source attribution and relevance scores
- **Maximum Results**: Capped at 20 chunks per search to prevent context overflow
