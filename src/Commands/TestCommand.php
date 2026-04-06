<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class TestCommand extends Command
{
    protected $signature = 'bedrock:test
                            {model? : Model ID to test directly (skips picker)}
                            {--connection= : Connection name}
                            {--prompt= : Custom test prompt}
                            {--max-tokens=200 : Max tokens for response}
                            {--all-keys : Test all configured credential keys}
                            {--sync : Sync models to database before picking}
                            {--json : Output as JSON}';

    protected $description = 'Test AWS Bedrock connection and optionally invoke a model';

    public function handle(BedrockManager $manager): int
    {
        $connection = $this->option('connection');
        $modelId    = $this->argument('model');

        if (! $manager->isConfigured($connection)) {
            $this->error('Bedrock is not configured. Run `php artisan bedrock:configure` first.');

            return 1;
        }

        $this->newLine();
        $this->line('  <fg=cyan>╔═══════════════════════════════════════════╗</>');
        $this->line('  <fg=cyan>║   AWS Bedrock Connection Test             ║</>');
        $this->line('  <fg=cyan>╚═══════════════════════════════════════════╝</>');
        $this->newLine();

        // ── Connection test ──────────────────────────────────────────
        $this->line('  <options=bold>Testing connection...</>');
        $result = $manager->testConnection($connection);

        if (! $result['success']) {
            $this->error('  ✗ Connection failed: ' . $result['message']);

            return 1;
        }

        $this->info('  ✓ ' . $result['message']);
        $this->line("  Response time: {$result['response_time']}ms");
        $this->newLine();

        // ── All keys ─────────────────────────────────────────────────
        if ($this->option('all-keys')) {
            $this->testAllKeys($manager, $connection);
        }

        // ── Model invocation ─────────────────────────────────────────
        if ($modelId) {
            return $this->testModel($manager, $connection, $modelId);
        }

        if (! $this->confirm('  Test a model invocation?', false)) {
            return 0;
        }

        // ── Sync option ──────────────────────────────────────────────
        if ($this->option('sync')) {
            $this->line('  Syncing models to database...');
            $count = $manager->syncModels($connection);
            $this->info("  ✓ Synced {$count} models.");
            $this->newLine();
        }

        $modelId = $this->pickModel($manager, $connection);

        if (! $modelId) {
            return 0;
        }

        return $this->testModel($manager, $connection, $modelId);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Model picker: provider → model (two-step)
    // ─────────────────────────────────────────────────────────────────

    protected function pickModel(BedrockManager $manager, ?string $connection): ?string
    {
        $this->line('  <options=bold>Fetching available models...</>');

        try {
            $grouped = $manager->getModelsGrouped($connection);
        } catch (\Throwable $e) {
            $this->error('  ✗ Could not load models: ' . $e->getMessage());

            return $this->ask('  Enter model ID manually');
        }

        if (empty($grouped)) {
            $this->warn('  No models found.');

            return $this->ask('  Enter model ID manually');
        }

        $totalModels = array_sum(array_map('count', $grouped));
        $this->info("  Found {$totalModels} models across " . count($grouped) . ' providers.');
        $this->newLine();

        // ── Step 1: choose provider ───────────────────────────────
        $providers = array_keys($grouped);
        $providerChoices = array_map(function (string $provider) use ($grouped) {
            $count     = count($grouped[$provider]);
            $activeCount = count(array_filter($grouped[$provider], fn ($m) => $m['is_active']));

            return "{$provider}  ({$activeCount} active / {$count} total)";
        }, $providers);

        $providerLabel = $this->choice(
            '  Select a provider',
            $providerChoices,
            0
        );

        // Resolve back to clean key
        $providerIndex = array_search($providerLabel, $providerChoices, true);
        $provider      = $providers[$providerIndex];
        $models        = $grouped[$provider];

        // ── Step 2: choose model ──────────────────────────────────
        $this->newLine();

        // Build display lines: pad model name for alignment
        $maxNameLen = max(array_map(fn ($m) => strlen($m['name']), $models));

        $modelChoices = array_map(function (array $model) use ($maxNameLen) {
            $status  = $model['is_active'] ? '<fg=green>active</>' : '<fg=yellow>legacy</>';
            $ctx     = number_format($model['context_window'] / 1000) . 'k ctx';
            $name    = str_pad($model['name'], min($maxNameLen, 50));

            return "{$name}  │  {$model['model_id']}  │  {$ctx}  │  {$status}";
        }, $models);

        // Strip ANSI for choice() since terminal decorators vary
        $plainChoices = array_map(fn ($c) => strip_tags($c), $modelChoices);

        $chosen = $this->choice(
            '  Select a model',
            $plainChoices,
            0
        );

        $modelIndex = array_search($chosen, $plainChoices, true);

        return $models[$modelIndex]['model_id'];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Invoke & display
    // ─────────────────────────────────────────────────────────────────

    protected function testModel(BedrockManager $manager, ?string $connection, string $modelId): int
    {
        $prompt    = $this->option('prompt') ?? 'Say hello in exactly 3 words.';
        $maxTokens = (int) $this->option('max-tokens');

        $this->newLine();
        $this->line("  <options=bold>Invoking:</> {$modelId}");
        $this->line("  <options=bold>Prompt:</> \"{$prompt}\"");
        $this->newLine();

        try {
            $result = $manager->invoke(
                $modelId,
                'You are a helpful assistant. Respond briefly.',
                $prompt,
                $maxTokens,
                0.5,
                null,
                $connection,
            );

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));

                return 0;
            }

            $this->info('  ✓ Model responded successfully!');
            $this->newLine();

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Response',       wordwrap($result['response'], 80, "\n", true)],
                    ['Input Tokens',   number_format($result['input_tokens'])],
                    ['Output Tokens',  number_format($result['output_tokens'])],
                    ['Total Tokens',   number_format($result['total_tokens'])],
                    ['Cost',           '$' . number_format($result['cost'], 6)],
                    ['Latency',        $result['latency_ms'] . 'ms'],
                    ['Key Used',       $result['key_used']],
                    ['Model ID',       $result['model_id']],
                ]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error('  ✗ Invocation failed: ' . $e->getMessage());
            $this->newLine();
            $this->line('  <fg=yellow>Tip:</> If this is an Anthropic model, you may need to submit use case');
            $this->line('  details in the AWS Bedrock console before first use.');
            $this->line('  See: <href=https://console.aws.amazon.com/bedrock/>https://console.aws.amazon.com/bedrock/</> → Model Catalog');

            return 1;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  All keys test
    // ─────────────────────────────────────────────────────────────────

    protected function testAllKeys(BedrockManager $manager, ?string $connection): void
    {
        $this->line('  <options=bold>Testing all credential keys...</>');
        $this->newLine();

        try {
            $client = $manager->client($connection);
            $keys   = $client->getCredentialManager()->list();

            $results = [];
            foreach ($keys as $key) {
                $client->getCredentialManager()->select($key['index']);
                $testResult = $client->testConnection();

                $results[] = [
                    $key['label'],
                    $key['region'],
                    $testResult['success'] ? '✓ Connected' : '✗ Failed',
                    $testResult['success']
                        ? ($testResult['response_time'] ?? '-') . 'ms'
                        : substr($testResult['message'], 0, 40),
                ];
            }

            $this->table(['Key', 'Region', 'Status', 'Details'], $results);
            $this->newLine();

            $client->getCredentialManager()->reset();
        } catch (\Exception $e) {
            $this->error('  Error testing keys: ' . $e->getMessage());
        }
    }
}
