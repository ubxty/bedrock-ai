<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class TestCommand extends Command
{
    protected $signature = 'bedrock:test
                            {model? : Model ID to test (e.g., anthropic.claude-3-5-sonnet-20241022-v2:0)}
                            {--connection= : Connection name}
                            {--prompt= : Custom test prompt}
                            {--max-tokens=100 : Max tokens for response}
                            {--all-keys : Test all configured credential keys}
                            {--json : Output as JSON}';

    protected $description = 'Test AWS Bedrock connection and optionally invoke a model';

    public function handle(BedrockManager $manager): int
    {
        $connection = $this->option('connection');
        $modelId = $this->argument('model');

        if (! $manager->isConfigured($connection)) {
            $this->error('Bedrock is not configured. Run `php artisan bedrock:configure` first.');

            return 1;
        }

        $this->info('');
        $this->info('  ╔═══════════════════════════════════════════╗');
        $this->info('  ║   AWS Bedrock Connection Test             ║');
        $this->info('  ╚═══════════════════════════════════════════╝');
        $this->info('');

        // Step 1: Connection test
        $this->info('  Testing connection...');
        $result = $manager->testConnection($connection);

        if (! $result['success']) {
            $this->error('  ✗ Connection failed: ' . $result['message']);

            return 1;
        }

        $this->info('  ✓ ' . $result['message']);
        $this->info("  Response time: {$result['response_time']}ms");
        $this->newLine();

        // Step 2: Test all keys if requested
        if ($this->option('all-keys')) {
            $this->testAllKeys($manager, $connection);
        }

        // Step 3: Model invocation test
        if ($modelId) {
            return $this->testModel($manager, $connection, $modelId);
        }

        // If no model specified, ask
        if ($this->confirm('Test a model invocation?', false)) {
            $modelId = $this->ask('Enter model ID (e.g., anthropic.claude-3-5-sonnet-20241022-v2:0)');

            if ($modelId) {
                return $this->testModel($manager, $connection, $modelId);
            }
        }

        return 0;
    }

    protected function testModel(BedrockManager $manager, ?string $connection, string $modelId): int
    {
        $prompt = $this->option('prompt') ?? 'Say hello in exactly 3 words.';
        $maxTokens = (int) $this->option('max-tokens');

        $this->info("  Invoking model: {$modelId}");
        $this->info("  Prompt: \"{$prompt}\"");
        $this->newLine();

        try {
            $client = $manager->client($connection);
            $startTime = microtime(true);

            $result = $client->invoke(
                $modelId,
                'You are a helpful assistant. Respond briefly.',
                $prompt,
                $maxTokens,
                0.5
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
                    ['Response', substr($result['response'], 0, 200)],
                    ['Input Tokens', number_format($result['input_tokens'])],
                    ['Output Tokens', number_format($result['output_tokens'])],
                    ['Total Tokens', number_format($result['total_tokens'])],
                    ['Cost', '$' . number_format($result['cost'], 6)],
                    ['Latency', $result['latency_ms'] . 'ms'],
                    ['Key Used', $result['key_used']],
                ]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error('  ✗ Invocation failed: ' . $e->getMessage());

            return 1;
        }
    }

    protected function testAllKeys(BedrockManager $manager, ?string $connection): void
    {
        $this->info('  Testing all credential keys...');
        $this->newLine();

        try {
            $client = $manager->client($connection);
            $keys = $client->getCredentialManager()->list();

            $results = [];
            foreach ($keys as $key) {
                $client->getCredentialManager()->select($key['index']);
                $testResult = $client->testConnection();

                $results[] = [
                    $key['label'],
                    $key['region'],
                    $testResult['success'] ? '✓ Connected' : '✗ Failed',
                    $testResult['success'] ? ($testResult['response_time'] ?? '-') . 'ms' : substr($testResult['message'], 0, 40),
                ];
            }

            $this->table(['Key', 'Region', 'Status', 'Details'], $results);
            $this->newLine();

            // Reset to first key
            $client->getCredentialManager()->reset();
        } catch (\Exception $e) {
            $this->error('  Error testing keys: ' . $e->getMessage());
        }
    }
}
