# Streaming (ubxty/bedrock-ai)

Streaming is supported on **IAM-auth** connections. Bearer-token (ABSK) mode
throws `Ubxty\BedrockAi\Exceptions\ConfigurationException` with the message
`Streaming is not supported in Bearer token mode. Use IAM credentials or the
non-streaming converse() method.`

## Public API

| Method | Returns | Notes |
|---|---|---|
| `BedrockManager::stream($modelId, $systemPrompt, $userMessage, callable $onChunk, int $maxTokens = 4096, float $temperature = 0.7, ?string $connection = null, ?array $pricing = null)` | `array` | Single-turn streaming. Calls the callback once per chunk, then returns the assembled result (`response`, `input_tokens`, `output_tokens`, `total_tokens`, `stop_reason`, `latency_ms`, `model_id`, `key_used`, `cost`). |
| `BedrockManager::converseStream($modelId, array $messages, callable $onChunk, string $systemPrompt = '', int $maxTokens = 4096, float $temperature = 0.7, ?string $connection = null)` | `array` | Multi-turn streaming. Inherited from `AbstractAiManager`. |
| `ConversationBuilder::sendStream(callable $onChunk): array` (core-ai v2.1.x) | `array` | Streams the conversation assembled so far. |
| `ConversationBuilder::stream(callable $onChunk): array` (core-ai v2.1.3+) | `array` | Alias of `sendStream()`. |
| `StreamingClient::converseStream($modelId, array $messages, callable $onChunk, ...)` | `array` | Lower-level client. |

> **There is no `BedrockManager::streamCallback()`, no `BedrockManager::withTools()`, no `BedrockClient::converseStream()`.** Streaming returns `array`, NOT a `StreamedResponse`.

## Call pattern

```php
use Ubxty\BedrockAi\Facades\Bedrock;

$text = '';
$result = Bedrock::stream(
    modelId:      'anthropic.claude-sonnet-4-20250514-v1:0',
    systemPrompt: 'Translate the user message to Mandarin.',
    userMessage:  'How do I open the trunk of a 2020 Camry?',
    onChunk:      function (string $chunk) use (&$text) { $text .= $chunk; echo $chunk; },
    maxTokens:    2048,
);

echo "\n--- used {$result['input_tokens']} in / {$result['output_tokens']} out, cost \${$result['cost']}";
```

The callback receives the **string fragment** of the current chunk. The returned `$result` array is the assembled final response.

## Bearer-token mode

```php
'connections' => [
    'default' => [
        'auth_mode' => 'bearer',   // ABSK
        'keys'      => ['bedrock-absk-...'],
    ],
],
```

Calling `Bedrock::stream(...)` on a `bearer` connection throws
`ConfigurationException` immediately. The non-streaming `converse()` method
works in bearer mode (no 4xx mapping done — the call goes through).

## Retry-After / key rotation

`Retry-After` is honoured **only on the initial 429 response** before any bytes
are streamed. Once a stream is in flight, the SDK iteration is a single loop —
there is no chunk-level reconnect/rotation logic in this package. If the
connection drops mid-stream, the stream terminates with the partial bytes
already delivered; callers should wrap in their own retry/observe layer.

## Cache

Do **not** wrap `Bedrock::stream()` in `Cache::remember()` — the result is an
`array`, and the result is also not currently cached by the response-cache
layer (it only memoises `invoke` / `converse`, not `stream` / `converseStream`).

## For HTTP-level SSE (chunked bytes to a browser)

`stream()` returns the assembled array, not a chunked response body. To send
chunks to a browser over HTTP, wrap it in a Laravel `response()->stream()`
or a Symfony `StreamedResponse` and call the callback with `fwrite($stream, …)`:

```php
return response()->stream(function () use ($manager) {
    $manager->stream('anthropic.claude-sonnet-4-20250514-v1:0', '…', '…',
        fn (string $chunk) => fwrite(STDOUT, $chunk));
}, 200, ['Content-Type' => 'text/event-stream']);
```

## Tool use

Tool use (`toolConfig`) is not exposed on `BedrockManager::stream()` — pass
`toolConfig` directly via the lower-level `StreamingClient::converseStream()`
or fall back to non-streaming `Bedrock::converse()`.
