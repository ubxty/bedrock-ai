# FAQ

> Companion to the [README](../README.md). Short, focused answers.

---

## Setup

**Q: I get `model use case details have not been submitted`. What now?**
A: AWS requires a one-time form per account for Anthropic models. Submit it at AWS Console → Bedrock → Model access → Claude → "Submit use case details". Approvals are typically 5-15 minutes.

**Q: Can I use this with a `~/.aws/credentials` profile?**
A: Yes. If `BEDROCK_AWS_KEY` is empty, the AWS SDK falls through to the standard chain (`~/.aws/credentials`, `AWS_PROFILE`, IAM role, etc.).

**Q: Does this work on Laravel 11|12 LTS?**
A: Yes. Laravel 11+ requires PHP 8.2+. Tested against Laravel 11.0 and 12.0.

**Q: Do I need `aws/aws-sdk-php` directly?**
A: It's pulled transitively. You don't need to require it. If you want the SDK in your own code for non-Bedrock AWS calls, `composer require aws/aws-sdk-php` is fine.

**Q: My region is `ap-south-1`. Does the package work there?**
A: Yes, but `nova-pro-v1:0` and the latest Claude are limited — Bedrock publishes a per-region support matrix. Check `php artisan bedrock:models` for what's available in your region.

**Q: Does this work with the AWS SDK's SSO credentials?**
A: Yes. The package picks up the SDK's standard credential chain, including SSO.

---

## Configuration

**Q: Where do I configure `multi-key failover`?**
A: `config/core-ai.php` → `connections.default.keys[]`. See [`multi-key-failover.md`](multi-key-failover.md).

**Q: Why does my `BEDROCK_BEARER_TOKEN` env not get picked up?**
A: Check `BEDROCK_AUTH_MODE=bearer` is also set. Bearer mode is opt-in.

**Q: Can I have different keys per Laravel environment?**
A: Yes — use multiple `connections` named blocks. Default is `default`. Switch at call time: `connection: 'staging'`.

**Q: How do I disable the response cache without changing the TTL?**
A: `config(['core-ai.cache.response_ttl' => 0])` at call time, or vary any of `temperature`, `maxTokens`, `systemPrompt`, `userMessage`, `modelId` (changes the SHA hash).

---

## Cost

**Q: How do I see what each tenant costs me?**
A: `tenant_id` should be in `tags[]` on every call. Use `UsageTracker` filtered to a tenant's model mix + `CostExplorerService::monthly()` reconciled monthly.

**Q: My billing is much higher than the per-invocation `cost` field. Why?**
A: The `cost` field uses on-demand rates. Reserved / provisioned throughput bill separately under `ce:GetCostAndUsage`. Reconcile via `CostExplorerService`.

**Q: Does enabling `cachePoint` save money on the FIRST call?**
A: No. The first call's prefix is billed at the full rate. Savings start on the second call within the TTL window.

**Q: Where are the AWS Pricing API numbers cached?**
A: `core-ai.bedrock.cache.pricing_ttl` (default 24 h). Force refresh with `PricingService::refresh()`.

---

## Errors

**Q: I'm getting `400 cachePoint is not supported for this model` on Titan.**
A: Bedrock prompt caching is only on Claude and select Nova models. Drop the cache points for Titan / Cohere calls.

**Q: Why is the streaming endpoint not working in production but works locally?**
A: Check reverse proxy (Nginx, Cloudflare) buffering. SSE responses must use `X-Accel-Buffering: no`. Also confirm you're using IAM auth mode, not Bearer.

**Q: What does `403: SignatureDoesNotMatch` mean?**
A: The AWS key and secret mismatch, or the system clock is off by more than 5 minutes. Confirm `date` on the host.

**Q: Why do I see `429` even though I'm not at any service quota?**
A: Bedrock has per-region TPM (tokens per minute) quotas. Check `Service Quotas` → `Amazon Bedrock` in the AWS Console for your region.

---

## Performance

**Q: My time-to-first-token for streaming is 3 seconds. Why?**
A: Latency depends on prompt size and model. System prompt caching doesn't reduce TTFC materially — it reduces input cost. For TTFC reduction, use a smaller model for prefill.

**Q: I'm hitting CloudWatch rate limits with `UsageTracker`.**
A: `UsageTracker` caches aggressively. If you're slamming it, enable `core-ai.bedrock.cache.usage_ttl = 3600`. If still not enough, batch the calls in your dashboard.

**Q: How many concurrent streams can I run?**
A: Up to 100 per region by default (raiseable via quota). The package doesn't pool, but `concurrency` keys in your own orchestrator are safe.

---

## Embeddings

**Q: Titan v2 with `dimensions=512` returns the same vectors as `dimensions=1024`?**
A: They differ. v2 with lower dimension truncates the vector in a normalised way — semantic similarity scores compare within a dimension cohort.

**Q: Can I cache embeddings indefinitely?**
A: Yes. Set `core-ai.cache.embedding_ttl` to a long value. The cache key is content-hashed; you don't need to invalidate when text changes (a different hash = a different key).

**Q: `embed()` is slow on 10k texts. Can I batch?**
A: The package doesn't auto-batch. Use a parallel queue (Laravel `Bus::batch`, `concurrency` driver) with chunks of 100-500. The per-row cache means re-runs are free.

---

## Operations

**Q: How do I monitor spend in real time?**
A: Listen on `BedrockInvoked`, aggregate `cost` field, push to CloudWatch / DataDog. Match against `CostExplorerService::monthly()` for reconciliation.

**Q: Can I rotate keys without re-deploying?**
A: Yes. Store keys in AWS Secrets Manager and pull via `secret()->value()` at boot. Config is loaded once at boot, so re-deploy is still needed for config changes — for hot rotation, rotate IAM access keys in AWS.

**Q: Where's the `invoke()` event payload?**
A: See [`events-and-listeners.md`](events-and-listeners.md). The event is `BedrockInvoked` (or the alias `AiInvoked`).

---

## Compatibility

**Q: Does this work alongside `openai-php/laravel` or `prism-php/prism`?**
A: Yes — but namespace conflicts may arise. Use distinct service containers (`$this->app->bind(...)`) for each provider.

**Q: Does the package include a queue worker monitor?**
A: No. Use Laravel's `horizon` or a third-party tool.

**Q: My Bedrock SDK error message is opaque. Where do I find more details?**
A: AWS SDK logs go to `storage/logs/laravel-*.log` if `LOG_LEVEL=debug`. Also enable `AWS_LOG` channel in your env.

**Q: Can I use this with an on-prem Bedrock clone (e.g. for air-gapped deployments)?**
A: Not out of the box. You'd need to fork the package and point the SDK at a custom endpoint. Submit an issue if this is a priority for you.
