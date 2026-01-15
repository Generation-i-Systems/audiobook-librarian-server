# AI Assistant for Book Management

## Overview

The AI Assistant is a conversational interface for managing books in your library. It allows you to use natural language to search, update, delete, and organize books with preview and approval workflows.

## Architecture

### Components

1. **AI Providers** (`app/Services/AI/Providers/`)
   - `BaseAIProvider` - Abstract base class with retry logic, rate limiting, and error handling
   - `GeminiProvider` - Google Gemini integration
   - `ClaudeProvider` - Anthropic Claude integration
   - `OpenAIProvider` - OpenAI GPT integration

2. **AI Assistant Service** (`app/Services/AI/AIAssistantService.php`)
   - Main business logic for processing requests
   - Intent analysis and operation generation
   - Preview and execution workflows
   - Conversation history management

3. **Controller** (`app/Http/Controllers/Admin/AIAssistantController.php`)
   - HTTP request handling
   - Session management
   - UI rendering

4. **Database** (`ai_assistant_sessions` table)
   - Stores conversation history
   - Tracks operations and execution status
   - Supports refinement across multiple requests

## Features

### 1. Natural Language Processing
- Analyzes user requests to determine intent (search, update, delete, etc.)
- Extracts parameters and search criteria
- Provides confidence scoring

### 2. Preview Before Execution
- Shows exactly what will be changed
- Allows selective execution (choose specific books)
- Displays before/after values for updates

### 3. Multi-Turn Conversations
- Supports follow-up questions and refinements
- Maintains conversation context
- Allows iterative improvement of operations

### 4. Supported Operations

#### Search
```
"Find all sci-fi books by Brandon Sanderson"
"Show me books with missing authors"
"List all fantasy books published after 2020"
```

#### Update
```
"Change the genre of all Terry Pratchett books to Fantasy"
"Update the author of 'Mistborn' to 'Brandon Sanderson'"
"Mark all books in the 'Foundation' series as Science Fiction"
```

#### Delete
```
"Delete all books with no author"
"Remove duplicate entries for 'The Stand'"
```

#### Tag/Categorize
```
"Tag all mystery books as 'Crime'"
"Mark all children's books with genre 'Kids'"
```

### 5. Refinement Workflow

1. **Initial Request**: "Find all fantasy books"
2. **Preview**: Shows 500 fantasy books
3. **Refinement**: "Only show books from Brandon Sanderson"
4. **Updated Preview**: Shows 23 books
5. **Approval**: User selects specific books and executes

## Usage

### Basic Flow

1. Navigate to `/admin/ai-assistant`
2. Enter your request in natural language
3. Review the preview of operations
4. (Optional) Refine your request with follow-up messages
5. Select which operations to execute
6. Approve and execute

### Configuration

Set in `config/services.php`:

```php
'ai' => [
    'default_model' => env('AI_DEFAULT_MODEL', 'gemini-2.5-flash-lite'),
    'default_provider' => env('AI_DEFAULT_PROVIDER', 'gemini'),
],
```

Environment variables:
```bash
AI_DEFAULT_PROVIDER=gemini  # or claude, openai
AI_DEFAULT_MODEL=gemini-2.5-flash-lite

GEMINI_API_KEY=your_key
CLAUDE_API_KEY=your_key
OPENAI_API_KEY=your_key
```

## Safety Features

### 1. Rate Limiting
- Per-minute and per-day request limits
- Automatic retry with exponential backoff
- Provider-specific limits enforced

### 2. Preview Before Execution
- Nothing is executed until explicitly approved
- Shows exact changes that will be made
- Allows selective execution

### 3. Conversation Tracking
- Full audit trail of requests and responses
- Session-based tracking
- Execution results stored for review

### 4. Error Handling
- Graceful degradation on API failures
- Partial execution support
- Detailed error logging

## Cost Management

### Usage Statistics

Access via `/admin/ai-assistant/stats`:

```json
{
  "session_cost": 0.0234,
  "requests_this_minute": 3,
  "requests_today": 47,
  "rate_limits": {
    "requests_per_minute": 15,
    "requests_per_day": 1000
  },
  "pricing": {
    "input_per_million": 0.10,
    "output_per_million": 0.40
  }
}
```

### Cost Optimization

1. **Use Free Tier Models**
   - Gemini Flash Lite has 1000 requests/day free
   - Set `AI_DEFAULT_MODEL=gemini-2.5-flash-lite`

2. **Structured Output**
   - JSON schema validation reduces retries
   - Clear prompts minimize token usage

3. **Caching**
   - Session-based conversation history reduces repeated context

## Extending

### Add New Operation Types

In `AIAssistantService.php`:

```php
protected function generateOperations(string $intent, array $parameters): array
{
    return match ($intent) {
        'your_new_intent' => $this->generateYourNewOperations($parameters),
        // ... existing intents
    };
}

protected function generateYourNewOperations(array $parameters): array
{
    // Your implementation
}
```

### Add New Provider

1. Create provider class extending `BaseAIProvider`
2. Implement abstract methods:
   - `callAPI()`
   - `callAPIWithSchema()`
   - `transcribe()`
   - `calculateUsage()`
   - `getName()` / `getModel()`

3. Add to constructor switch in `AIAssistantService`:

```php
$this->provider = match ($providerName) {
    'your_provider' => new YourProvider($model),
    // ... existing providers
};
```

## Troubleshooting

### Common Issues

1. **"Rate limit exceeded"**
   - Wait for rate limit window to reset
   - Check usage stats: `/admin/ai-assistant/stats`
   - Consider upgrading to paid tier

2. **"Failed to parse structured response"**
   - AI returned invalid JSON
   - Automatic retry will attempt again
   - Check logs for full response

3. **"Session not found"**
   - Session may have expired
   - Start a new session from `/admin/ai-assistant`

### Debugging

Enable detailed logging:

```php
Log::info('AI Assistant Debug', [
    'session_id' => $sessionId,
    'provider' => $this->provider->getName(),
    'conversation' => $this->conversationHistory,
]);
```

## Performance

### Benchmarks

Typical request:
- Analysis: ~1-2 seconds
- Operation generation: ~2-4 seconds
- Total: ~3-6 seconds

Execution:
- Per book: ~10-50ms
- Batch of 100 books: ~1-5 seconds

### Optimization Tips

1. Limit search results to necessary fields only
2. Use indexed columns in search criteria
3. Execute in batches if operating on >1000 books
4. Consider background jobs for very large operations

## Security

### Access Control

- Requires authentication (`auth` middleware)
- Admin-only access (`admin` middleware)
- User-scoped sessions (can only access own sessions)

### Input Validation

- Request validation at controller level
- SQL injection protection via query builder
- Rate limiting prevents abuse

### Audit Trail

- All operations logged
- Execution results stored
- Full conversation history retained

## Comparison to Old System

| Feature | Old System | New System |
|---------|-----------|------------|
| Provider Abstraction | Manual switching | Clean interface |
| Retry Logic | None | Exponential backoff |
| Rate Limiting | Basic cache | Provider-specific |
| Conversation | None | Multi-turn support |
| Preview | Limited | Full preview with refinement |
| Cost Tracking | Manual estimation | Automatic per-session |
| Validation | None | JSON schema validation |

## Migration Guide

The old `AIBookProcessor` still works for metadata extraction. The new system is for **book management operations** only.

Use old system for:
- Extracting metadata from audio files
- Processing new book imports
- Audio transcription

Use new system for:
- Searching existing books
- Bulk updates to metadata
- Organizing and tagging
- Finding data quality issues
