<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class ChatCommand extends Command
{
    protected $signature = 'bedrock:chat
                            {model? : Model ID or alias to chat with}
                            {--connection= : Connection name}
                            {--system= : System prompt for the conversation}
                            {--max-tokens=4096 : Max tokens per response}
                            {--temperature=0.7 : Temperature for responses}
                            {--no-stream : Disable streaming (wait for full response)}';

    protected $description = 'Start an interactive chat session with a Bedrock model';

    protected int $totalInputTokens = 0;

    protected int $totalOutputTokens = 0;

    protected float $totalCost = 0;

    protected int $messageCount = 0;

    /** Per-session cache-mode choice. null = honour package config. */
    protected ?bool $cachingEnabled = null;

    public function handle(BedrockManager $manager): int
    {
        $connection = $this->option('connection');

        if (! $manager->isConfigured($connection)) {
            $this->error('Bedrock is not configured. Run `php artisan bedrock:configure` first.');

            return 1;
        }

        $modelId = $this->argument('model');

        if (! $modelId) {
            $defaultModel = $manager->defaultModel();

            if ($defaultModel) {
                $this->line("  Default model: <fg=cyan>{$defaultModel}</>");
                $useDefault = $this->confirm('  Use default model?', true);

                if ($useDefault) {
                    $modelId = $defaultModel;
                } else {
                    $modelId = $this->selectModel($manager, $connection);
                }
            } else {
                $modelId = $this->selectModel($manager, $connection);
            }

            if (! $modelId) {
                return 1;
            }
        }

        $modelId = $manager->resolveAlias($modelId);
        $systemPrompt = $this->option('system') ?? 'You are a helpful AI assistant.';
        $maxTokens = (int) $this->option('max-tokens');
        $temperature = (float) $this->option('temperature');
        $useStreaming = ! $this->option('no-stream');

        // Cache-mode prompt: only when the package config has anchors AND the
        // selected model actually supports cachePoint markers. Otherwise the
        // question is moot (nothing to enable) or pointless (the request would
        // 403).
        if ($manager->packageCachePointsConfigured() && $manager->modelSupportsCaching($modelId)) {
            $this->cachingEnabled = $this->confirm('  Use cached mode for this session?', true);
        }

        $this->printHeader($modelId, $systemPrompt, $useStreaming);

        $conversation = $manager->conversation($modelId)
            ->system($systemPrompt)
            ->maxTokens($maxTokens)
            ->temperature($temperature);

        if ($connection) {
            $conversation->connection($connection);
        }

        $this->applyCachePointsOverride($conversation);

        // Main chat loop
        while (true) {
            $this->newLine();
            $input = $this->ask('<fg=green>You</>');

            if ($input === null || $input === '') {
                continue;
            }

            $command = strtolower(trim($input));

            if (in_array($command, ['/quit', '/exit', '/q'])) {
                break;
            }

            if ($command === '/help') {
                $this->printHelp();

                continue;
            }

            if ($command === '/stats') {
                $this->printStats();

                continue;
            }

            if ($command === '/reset') {
                $conversation->reset();
                $this->totalInputTokens = 0;
                $this->totalOutputTokens = 0;
                $this->totalCost = 0;
                $this->messageCount = 0;
                $this->info('  Conversation reset.');

                continue;
            }

            if (str_starts_with($command, '/system ')) {
                $newSystem = substr($input, 8);
                $conversation->system($newSystem);
                $this->info("  System prompt updated.");

                continue;
            }

            if (str_starts_with($command, '/model ')) {
                $newModel = trim(substr($input, 7));
                $newModel = $manager->resolveAlias($newModel);
                $conversation = $manager->conversation($newModel)
                    ->system($conversation->getSystemPrompt())
                    ->maxTokens($maxTokens)
                    ->temperature($temperature);

                if ($connection) {
                    $conversation->connection($connection);
                }

                $this->applyCachePointsOverride($conversation);

                $modelId = $newModel;
                $this->info("  Switched to model: {$newModel}");

                continue;
            }

            if (preg_match('#^/cache(\s+(on|off))?\s*$#i', $command, $m)) {
                $arg = strtolower($m[2] ?? '');
                if ($arg === 'on') {
                    $this->cachingEnabled = true;
                } elseif ($arg === 'off') {
                    $this->cachingEnabled = false;
                } else {
                    $this->cachingEnabled = ! $this->cachingEnabled;
                }
                $this->applyCachePointsOverride($conversation);
                $this->info('  Caching: '.($this->cachingEnabled ? '<fg=green>On</>' : '<fg=yellow>Off</>'));

                continue;
            }

            if (str_starts_with($command, '/temp ')) {
                $newTemp = (float) trim(substr($input, 6));
                $newTemp = max(0.0, min(1.0, $newTemp));
                $conversation->temperature($newTemp);
                $temperature = $newTemp;
                $this->info("  Temperature set to: {$newTemp}");

                continue;
            }

            // /image <path> [prompt] — analyse an image file
            if (str_starts_with($command, '/image ')) {
                $this->handleFileCommand(
                    $input, 7, 'image',
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    'Describe this image in detail.',
                    $conversation, $manager, $connection, $useStreaming,
                );

                continue;
            }

            // /doc <path> [prompt] — analyse a document (PDF, CSV, DOCX, etc.)
            if (str_starts_with($command, '/doc ')) {
                $this->handleFileCommand(
                    $input, 5, 'document',
                    ['pdf', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'html', 'htm', 'txt', 'md'],
                    'Summarize this document.',
                    $conversation, $manager, $connection, $useStreaming,
                );

                continue;
            }

            $conversation->user($input);

            try {
                $this->line('');
                $this->line('  <fg=cyan>Assistant</>');

                if ($useStreaming && ! $manager->client($connection)->getCredentialManager()->isBearerMode()) {
                    $result = $this->sendStreaming($conversation);
                } else {
                    $result = $this->sendBlocking($conversation);
                }

                $this->messageCount++;
                $this->totalInputTokens += $result['input_tokens'] ?? 0;
                $this->totalOutputTokens += $result['output_tokens'] ?? 0;
                $this->totalCost += $result['cost'] ?? 0;

                $this->newLine();
                $this->line(sprintf(
                    '  <fg=gray>[%d in / %d out / %dms]</>',
                    $result['input_tokens'] ?? 0,
                    $result['output_tokens'] ?? 0,
                    $result['latency_ms'] ?? 0,
                ));
            } catch (\Exception $e) {
                // Remove the failed message and restore conversation state
                $messages = $conversation->getMessages();
                array_pop($messages);
                $conversation->reset()->setMessages($messages);

                $this->error('  Error: ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->printStats();
        $this->info('  Goodbye!');
        $this->newLine();

        return 0;
    }

    protected function selectModel(BedrockManager $manager, ?string $connection): ?string
    {
        $this->info('Fetching available models...');

        try {
            $grouped = $manager->getModelsGrouped($connection, 'chat');
        } catch (\Exception $e) {
            $this->error('Failed to fetch models: ' . $e->getMessage());

            return null;
        }

        if (empty($grouped)) {
            $this->error('No models available.');

            return null;
        }

        // Flatten grouped models and filter to text-capable active models
        $allModels = array_merge(...array_values($grouped));
        $textModels = array_values(array_filter($allModels, function ($m) {
            return $m['is_active'] && in_array('text', $m['capabilities'] ?? []);
        }));

        if (empty($textModels)) {
            $textModels = $allModels;
        }

        // Show a compact numbered list
        $this->newLine();
        $this->info('  Available Models');
        $this->line('  ─────────────────────────────────────────────');

        $choices = [];
        foreach ($textModels as $i => $model) {
            $num = $i + 1;
            $name = $model['name'] ?: $model['model_id'];
            $ctx = number_format($model['context_window']);
            $badge = $manager->modelSupportsCaching($model['model_id'])
                ? ' <fg=magenta>[cached]</>'
                : '';
            $this->line(sprintf('  <fg=yellow>%3d</> │ %s <fg=gray>(%s ctx)</>%s',
                $num, $name, $ctx, $badge
            ));
            $choices[$num] = $model['model_id'];
        }

        $this->newLine();
        $selection = $this->ask('Select a model (number or model ID)');

        if (! $selection) {
            return null;
        }

        if (is_numeric($selection) && isset($choices[(int) $selection])) {
            return $choices[(int) $selection];
        }

        return $selection;
    }

    /**
     * Handle /image and /doc commands (shared logic).
     */
    protected function handleFileCommand(
        string $input,
        int $prefixLen,
        string $type,
        array $allowedExtensions,
        string $defaultPrompt,
        $conversation,
        BedrockManager $manager,
        ?string $connection,
        bool $useStreaming,
    ): void {
        $args = trim(substr($input, $prefixLen));
        $filePath = null;
        $prompt = $defaultPrompt;

        if (preg_match('/^("(?<quoted>[^"]+)"|(?<bare>\S+))\s*(?<prompt>.*)$/s', $args, $m)) {
            $filePath = $m['quoted'] ?: $m['bare'];
            if (! empty(trim($m['prompt']))) {
                $prompt = trim($m['prompt']);
            }
        }

        if (! $filePath || ! is_file($filePath)) {
            $this->error('  File not found: ' . ($filePath ?: '(none)'));
            $this->line("  <fg=gray>Usage: /{$type} /path/to/file [optional prompt]</>");

            return;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (! in_array($ext, $allowedExtensions)) {
            $this->error("  Unsupported {$type} format: .{$ext}");
            $this->line('  <fg=gray>Supported: ' . implode(', ', $allowedExtensions) . '</>');

            return;
        }

        $sizeKb = round(filesize($filePath) / 1024);
        $label = $type === 'image' ? 'image' : 'document';
        $this->line("  <fg=gray>Sending {$label}: {$filePath} ({$sizeKb} KB)</>");

        try {
            if ($type === 'image') {
                $conversation->userWithImage($prompt, $filePath);
            } else {
                $conversation->userWithDocument($prompt, $filePath);
            }

            $this->line('');
            $this->line('  <fg=cyan>Assistant</>');

            if ($useStreaming && ! $manager->client($connection)->getCredentialManager()->isBearerMode()) {
                $result = $this->sendStreaming($conversation);
            } else {
                $result = $this->sendBlocking($conversation);
            }

            $this->messageCount++;
            $this->totalInputTokens += $result['input_tokens'] ?? 0;
            $this->totalOutputTokens += $result['output_tokens'] ?? 0;
            $this->totalCost += $result['cost'] ?? 0;

            $this->newLine();
            $this->line(sprintf(
                '  <fg=gray>[%d in / %d out / %dms]</>',
                $result['input_tokens'] ?? 0,
                $result['output_tokens'] ?? 0,
                $result['latency_ms'] ?? 0,
            ));
        } catch (\Exception $e) {
            // Roll back the failed multimodal message
            $messages = $conversation->getMessages();
            array_pop($messages);
            $conversation->reset()->setMessages($messages);

            $this->error('  Error: ' . $e->getMessage());
        }
    }

    protected function sendStreaming($conversation): array
    {
        $result = $conversation->sendStream(function (string $chunk) {
            $this->output->write($chunk);
        });

        return $result;
    }

    protected function sendBlocking($conversation): array
    {
        $result = $conversation->send();
        $this->line('  ' . $result['response']);

        return $result;
    }

    protected function printHeader(string $modelId, string $systemPrompt, bool $streaming): void
    {
        $this->info('');
        $this->info('  ╔═══════════════════════════════════════════╗');
        $this->info('  ║   AWS Bedrock Chat Session                ║');
        $this->info('  ╚═══════════════════════════════════════════╝');
        $this->info('');
        $this->line("  Model:     <fg=cyan>{$modelId}</>");
        $this->line("  System:    <fg=gray>" . substr($systemPrompt, 0, 60) . (strlen($systemPrompt) > 60 ? '...' : '') . '</>');
        $this->line('  Streaming: ' . ($streaming ? '<fg=green>On</>' : '<fg=yellow>Off</>'));
        $this->line('  Caching:   ' . $this->cachingHeader());
        $this->line('');
        $this->line('  Type your message and press Enter. Commands:');
        $this->line('  <fg=yellow>/help</> - Show all commands  <fg=yellow>/quit</> - Exit session');
        $this->line('  ─────────────────────────────────────────────');
    }

    /**
     * Render the Caching: row in the chat header. Three states:
     *   - null  → <fg=gray>Default (package config)</>
     *   - true  → <fg=green>On</>
     *   - false → <fg=yellow>Off</>
     */
    protected function cachingHeader(): string
    {
        return match ($this->cachingEnabled) {
            true  => '<fg=green>On</>',
            false => '<fg=yellow>Off</>',
            default => '<fg=gray>Default (package config)</>',
        };
    }

    /**
     * Apply the current cache-mode choice to a fresh conversation builder.
     * Called on initial model selection, after `/model` switches, and after
     * `/cache on|off` toggles.
     */
    protected function applyCachePointsOverride($conversation): void
    {
        if ($this->cachingEnabled === null) {
            return;
        }

        $conversation->cachePoints(
            $this->cachingEnabled ? $this->manager()->configuredCachePoints() : []
        );
    }

    protected function manager(): BedrockManager
    {
        return app(BedrockManager::class);
    }

    protected function printHelp(): void
    {
        $this->newLine();
        $this->info('  Chat Commands');
        $this->line('  ─────────────────────────────────────────────');
        $this->line('  <fg=yellow>/quit</>           Exit the chat session');
        $this->line('  <fg=yellow>/help</>           Show this help message');
        $this->line('  <fg=yellow>/stats</>          Show session statistics');
        $this->line('  <fg=yellow>/reset</>          Clear conversation history');
        $this->line('  <fg=yellow>/system <text></>  Change the system prompt');
        $this->line('  <fg=yellow>/model <id></>     Switch to a different model');
        $this->line('  <fg=yellow>/temp <0-1></>     Change temperature');
        $this->line('  <fg=yellow>/cache on|off</>  Toggle prompt caching for this session');
        $this->line('  <fg=yellow>/image <path> [prompt]</>');
        $this->line('                    Analyse an image (jpg/png/gif/webp)');
        $this->line('  <fg=yellow>/doc <path> [prompt]</>');
        $this->line('                    Analyse a document (pdf/csv/docx/xlsx/html/txt/md)');
    }

    protected function printStats(): void
    {
        $this->info('  Session Statistics');
        $this->line('  ─────────────────────────────────────────────');
        $this->line("  Messages:      {$this->messageCount}");
        $this->line('  Input Tokens:  ' . number_format($this->totalInputTokens));
        $this->line('  Output Tokens: ' . number_format($this->totalOutputTokens));
        $this->line('  Total Tokens:  ' . number_format($this->totalInputTokens + $this->totalOutputTokens));

        if ($this->totalCost > 0) {
            $this->line('  Estimated Cost: $' . number_format($this->totalCost, 6));
        }
    }
}
