# MCP Tool Calling Implementation Analysis

This document details the implementation of Model Context Protocol (MCP) tool calling within the application. The implementation enables Large Language Models (LLMs) to interact with external tools defined via MCP endpoints.

## 1. Architecture Overview

The system implements a modular architecture where:
- **McpClient** handles the low-level JSON-RPC communication with MCP servers.
- **AgentExecutor** orchestrates the conversation loop (LLM -> Tool -> LLM).
- **ToolRegistry** manages tool availability and configuration.
- **BotService** integrates the agent execution into the standard bot message processing flow.

## 2. Key Components

### 2.1. McpClient (`lib/Service/McpClient.php`)
This service acts as the HTTP client for MCP servers. It strictly follows the MCP JSON-RPC 2.0 specification.

- **Protocol**: JSON-RPC 2.0 over HTTP.
- **Methods**:
  - `listTools(Tool $tool)`: Sends `tools/list` request to discover available tools and their schemas.
  - `callTool(Tool $tool, string $toolName, array $arguments)`: Sends `tools/call` request to execute a specific tool.
- **Authentication**: Handles `Bearer` token or custom header injection defined in the tool's configuration.
- **Error Handling**: Wraps HTTP errors and JSON-RPC error responses into PHP Exceptions.

### 2.2. AgentExecutor (`lib/Service/AgentExecutor.php`)
The core "brain" of the tool calling process. It manages the ReAct-style loop (Reasoning + Acting).

- **Responsibility**:
  1. **Discovery**: Fetches tool definitions via `McpClient`.
  2. **Translation**: Converts MCP tool schemas into OpenAI-compatible `tools` definitions.
  3. **Execution Loop**:
     - Sends conversation history + tool definitions to LLM.
     - detects `tool_calls` in LLM response.
     - Executes tools via `McpClient`.
     - Appends tool results to history.
    - Repeats until LLM produces a final text response or max iterations (default 10) are reached.
- **Features**:
  - **Native Tool Calling**: Supports standard OpenAI `tool_calls` API.
  - **Fallback Parsing**: Attempts to parse JSON from plain text responses for models that don't natively support tools but output JSON.
  - **Forced Execution**: Can inject a forced tool call (e.g., for "search") if the user query strongly implies it but the model hesitated.
  - **Parameter Injection**: Automatically injects the user's original query into tool arguments (e.g., `query` parameter) if missing.

### 2.3. ToolRegistry (`lib/Service/ToolRegistry.php`)
Manages the mapping between Bots and Tools.

- **Function**: Retrieves enabled tools for a specific bot instance.
- **Configuration**: Merges global tool definition with bot-specific configuration overrides (stored in `educai_bot_tools`).

### 2.4. BotService (`lib/Service/BotService.php`)
The entry point for user messages.

- **Logic**:
  - Checks if the bot has assigned tools.
  - Calculates if a "Forced Tool Call" is necessary based on heuristics (keywords like "search", "web", "browser").
  - Delegates to `AgentExecutor` if tools are present; otherwise falls back to simple `LLMClient` completion.
  - Persists only `user` and `assistant` messages in `educai_conversations` (tool invocations are not stored; they are logged to Nextcloud logs).

## 3. The Tool Calling Process (Step-by-Step)

### Step 1: Message Reception & Preparation
In `BotService::processMessage`:
1. User message is received.
2. If RAG is enabled and indexed documents exist, RAG instructions are appended to the **system prompt** (RAG results are retrieved via the built-in `rag_search_documents` tool during the agent loop).
3. `ToolRegistry::getToolsForBot($botId)` retrieves assigned tools.

### Step 2: Agent Initialization
If tools are present, `AgentExecutor::run` is called with:
- System Prompt
- Message History
- Tool Loadout
- LLM Options (Model, Temperature, optional `force_tool_call` flag)

### Step 3: Tool Definition Build (`AgentExecutor::buildToolDefinitions`)
1. `AgentExecutor` iterates over assigned tools.
2. Calls `McpClient::listTools` for each.
3. Maps MCP schemas to OpenAI function signatures:
   ```json
   {
     "type": "function",
     "function": {
       "name": "tool_name",
       "description": "Tool description from MCP",
       "parameters": { ...json_schema... }
     }
   }
   ```

### Step 4: The Agent Loop
`AgentExecutor` enters a `while(true)` loop:

1.  **LLM Request**: Calls `LLMClient::sendChatCompletion` (or `streamChatCompletion` if streaming is enabled) with the tool definitions.
2.  **Response Analysis**:
    - **Case A: Native Tool Call**: LLM returns `tool_calls` array.
    - **Case B: Fallback JSON**: If content looks like JSON, `tryParseJson` attempts to reconstruct a tool call.
    - **Case C: Forced Call**: If no call is made but `force_tool_call` is true, a tool call is synthetically constructed (e.g., for search).
    - **Case D: Text Response**: If no tools are called, the loop breaks and returns the text.

3.  **Tool Execution**:
    - Iterates through `tool_calls`.
    - **Argument Autofill**: Checks if `query` parameter is missing and injects the user's original prompt if needed (`autofillArguments`).
    - **MCP Call**: Executes `McpClient::callTool`.
    - **Sanitization**: Truncates large outputs (>4000 chars) to prevent context window overflow.

4.  **History Update**:
    - Appends the `assistant` message (with tool calls).
    - Appends `tool` messages (with results).
    - Loop continues (LLM sees results and decides next step).

### Step 5: Final Response & Storage
1. `BotService` receives the final text content and the trace of tool invocations.
2. Stores final assistant response (role: `assistant`) and returns it to the user.
3. Tool invocations remain in-memory for the agent loop and are not persisted to `educai_conversations`.

## 4. Data Structures

### Database: `educai_tools` (`lib/Db/Tool.php`)
- `mcp_endpoint_url`: Base URL for the MCP server.
- `authentication`: JSON blob for headers/bearer tokens.
- `capabilities`: (Unused currently, reserved for future capability negotiation).

### Database: `educai_bot_tools` (`lib/Db/BotTool.php`)
- `bot_id` / `tool_id`: Many-to-many link.
- `config_override`: JSON configuration specific to this bot-tool pair (e.g., default parameters).

## 5. Heuristics & Safety

1.  **Forced Tool Calling**:
    - `BotService` checks for keywords ("search", "google", "web") or bot mention names containing "search".
    - If detected, `AgentExecutor` is instructed to force a tool call if the model doesn't make one voluntarily.

2.  **Fallback Query Injection**:
    - If a tool requires a `query` parameter but the LLM omits it, `AgentExecutor` injects the sanitized user message.

3.  **Loop Prevention**:
    - `max_iterations` (default 10) prevents infinite tool loops.
    - If limit is reached, returns "Tool interaction limit reached without final answer."

4.  **Output Sanitization**:
    - Tool outputs are JSON encoded.
    - Truncated to 4000 characters to protect token limits.

