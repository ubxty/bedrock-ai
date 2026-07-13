# Streaming Responses

> Companion to the [README](../README.md). `converseStream()` / `stream()` for real-time token delivery via Server-Sent Events (SSE) and `Symfony\Component\HttpFoundation\StreamedResponse`.

---

## Two streaming entry points

| Method | Underlying call | Output |
|---|---|---|
| `BedrockManager::stream($modelId, $system, $user, maxTokens: N, …)` | `ConverseClient::converseStream()` | `Symfony\Component\HttpFoundation\StreamedResponse` |
| `BedrockClient::converseStream($modelId, $system, $user, …)` | AWS SDK `ConverseStream` | Generator yielding chunks |
| `ConversationBuilder::stream()` | Same as above | StreamedResponse |
| `BedrockManager::streamCallback($modelId, $system, $user, $callback)` | Same as above | `void` — invokes callback per chunk |

IAM auth mode is required for streaming. Bearer mode (ABSK token) does not support streaming.

---

## TL;DR — Inertia / Vue streaming

```php
use Ubxty\BedrockAi\Facades\Bedrock;

Route::get('/stream', function () {
    return Bedrock::stream(
        'anthropic.claude-sonnet-4-20250514-v1:0',
        'You are a careful assistant.',
        request('q'),
        maxTokens: 1024,
    );
});
```

On the Vue / React side, consume the response with `fetch()` + a stream reader (or use `@vueuse/core`'s `useEventSource`). The HTTP body is `Content-Type: text/event-stream`, each event is `data: {json}\n\n`, terminated by `data: [DONE]\n\n`.

Use `Bedrock::streamCallback()` if you don't want to expose the stream directly (e.g. inside a queue job):

```php
Bedrock::streamCallback(
    'amazon.nova-pro-v1:0',
    '…', '…',
    fn ($chunk) => Storage::append('aistream.log', $chunk['text']),
);
```

---

## Streaming JSON shape

Each SSE event from `Bedrock::stream()` is a JSON object (compact):

```json
{"chunk":"Hello","index":0,"modelId":"anthropic.claude-sonnet-4-20250514-v1:0"}
{"chunk":" world","index":1}
…
{"chunk":"","index":42,"stopReason":"end_turn","usage":{"input":12,"output":47},"key_used":"Primary","latency_ms":1217}
```

The final event has empty `chunk`, populated `stopReason`, `usage`, and `key_used`.

---

## `BedrockClient::converseStream()` — Generator form

For backend consumers that don't need an HTTP layer (queue jobs, CLI tools, tests):

```php
foreach ($client->converseStream('anthropic.claude-sonnet-4-20250514-v1:0', '…', '…') as $event) {
    fwrite(STDOUT, $event['chunk']);
    if (!empty($event['stopReason'])) {
        Log::info('done', ['usage' => $event['usage']]);
        break;
    }
}
```

The generator yields associative arrays with the same shape as the SSE events.

---

## `ConversationBuilder::stream()`

```php
return Bedrock::conversation('amazon.nova-pro-v1:0')
    ->system('Translate the user message to Mandarin.')
    ->user('How do I open the trunk of a 2020 Camry?')
    ->image('/tmp/camry-trunk.png')
    ->maxTokens(2048)
    ->stream();
```

Multimodal inputs work the same as in `converse()`. The streamed chunks are the model's output tokens intermixed with image-reference metadata if the model returns structured annotations.

---

## Authentication caveats

| Auth | Streaming |
|---|---|
| `iam` (long-lived access key) | ✓ full streaming |
| `bearer` (ABSK token) | ✗ not supported — `BedrockException: Bearer mode does not support streaming` |

If you call `converseStream()` with Bearer auth, the client throws synchronously. Catch it and either rotate to an IAM-mode key (via the credential manager) or fall back to `invoke()` (no streaming).

---

## `Retry-After` honouring in streaming

`ConverseClient` (inherited from `HasRetryLogic`) tracks `Retry-After` for stream reconnects:

- Mid-stream connection drop → `Retry-After` honoured before reconnecting.
- Reconnect budget: 2 attempts per `BEDROCK_MAX_RETRIES` for streaming.
- After 2 reconnect attempts, the stream yields a final `{"chunk":"","stopReason":"error","usage":{...}}` and the generator exits.

Streaming never rotates to a different key during a single stream — the connection is restarted on the same key with `Retry-After`. Rotation only happens between streams.

---

## Stop reasons

| `stopReason` value | Meaning |
|---|---|
| `end_turn` | Model finished naturally. |
| `max_tokens` | Hit `maxTokens` — consider raising it. |
| `stop_sequence` | Hit a custom stop sequence. |
| `tool_use` | Model wants to invoke a tool (Bedrock tool_use). Yield the tool-use block, call the tool, send the result back. |
| `content_filtered` | Bedrock content filter tripped. Retry with a softer prompt. |
| `error` | Stream interrupted and `Retry-After` exhausted. |

`tool_use` in streaming requires the client's tool config — see `BedrockManager::withTools()`. Tool use is **not** auto-routed by the package; you wire the tool-result loop on your side.

---

## Response cache + streaming interaction

The package does NOT cache streamed responses. `core-ai.cache.response_ttl` only applies to `invoke()` / `converse()` (non-streaming). If you want to cache a streamed output as a single string, collect chunks then call `Cache::put()` explicitly:

```php
$output = '';
foreach ($client->converseStream(...) as $event) {
    $output .= $event['chunk'];
    if (!empty($event['stopReason'])) break;
}

Cache::put("mykey.$userId", $output, now()->addHour());
```

---

## Prompt cache markers + streaming

`cachePoint` markers work the same way for streaming as for `converse()`. The cached prefix is billed at the cached rate even when the output is streamed.

---

## Performance budgets

For a 2k-token output on sonnet-4 streaming:

| Metric | Budget |
|---|---|
| Time to first chunk (TTFC) | < 800 ms |
| Average chunk delivery | 30-80 ms per chunk |
| Total wall-clock | 4-8 s (depends on maxTokens) |
| Tokens / second | 200-600 |

If TTFC is high consistently, suspect prompt caching or routing. If inter-chunk delivery is high, suspect throttling or rate limit. Listen to `BedrockRateLimited` and `BedrockKeyRotated`.

---

## SSE error envelope

If the stream fails after the headers are sent (e.g. a mid-stream quota exhausted), the package closes the SSE connection with:

```
event: error
data: {"message":"stream interrupted","stopReason":"error","key_used":"Primary"}
```

Followed by the standard `data: [DONE]`. Clients should treat a missing final event as a stream failure and surface the partial output to the user.

---

## Browser wiring — Vue example

```js
async function streamBedrock(prompt) {
  const response = await fetch('/ai/stream?q=' + encodeURIComponent(prompt));
  const reader = response.body.getReader();
  const decoder = new TextDecoder();

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    const chunk = decoder.decode(value);
    for (const line of chunk.split('\n\n')) {
      if (!line.startsWith('data:')) continue;
      const payload = line.slice(5).trim();
      if (payload === '[DONE]') return;
      try {
        const evt = JSON.parse(payload);
        appendToUi(evt.chunk);
      } catch (e) {}
    }
  }
}
```

If you want SSE-specific parsing, use [`eventsource-parser`](https://www.npmjs.com/package/eventsource-parser) (8-line wrapper). The route handler is shown above (single line `Bedrock::stream(...)`).

---

## Don'ts

- Don't `Cache::remember()` a `Bedrock::stream()` result — the result IS a `StreamedResponse`, not a string.
- Don't put stream endpoints behind a synchronous CDN cache (Cloudflare / Varnish) without `Cache-Control: no-store` and chunked encoding passthrough.
- Don't rotate to a different key mid-stream — the package doesn't, but if you write a custom retry, don't.
- Don't reuse the same `BedrockClient` instance for two simultaneous streams — the credential counter is per-instance. Use the manager.
