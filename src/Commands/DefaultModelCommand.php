<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class DefaultModelCommand extends Command
{
    protected $signature = 'bedrock:model
                            {--show    : Show the current default model}
                            {--reset   : Clear the default model}
                            {--connection= : Use a specific connection}';

    protected $description = 'Set or show the default Bedrock model (writes BEDROCK_DEFAULT_MODEL to .env)';

    public function handle(BedrockManager $manager): int
    {
        $connection = $this->option('connection') ?: null;

        // ── --show ────────────────────────────────────────────────
        if ($this->option('show')) {
            $current = $manager->defaultModel();
            if ($current) {
                $this->info("  Current default model: <options=bold>{$current}</>");
            } else {
                $this->warn('  No default model set (BEDROCK_DEFAULT_MODEL is empty).');
            }

            return self::SUCCESS;
        }

        // ── --reset ───────────────────────────────────────────────
        if ($this->option('reset')) {
            $this->writeEnv(['BEDROCK_DEFAULT_MODEL' => '']);
            $this->info('  Default model cleared.');

            return self::SUCCESS;
        }

        // ── Interactive picker ────────────────────────────────────
        $this->info('');
        $this->info('  ╔═══════════════════════════════════════════╗');
        $this->info('  ║   Set Default Bedrock Model               ║');
        $this->info('  ╚═══════════════════════════════════════════╝');
        $this->info('');

        $current = $manager->defaultModel();
        if ($current) {
            $this->line("  Current default: <fg=cyan>{$current}</>");
            $this->newLine();
        }

        $modelId = $this->pickModel($manager, $connection);

        if (! $modelId) {
            $this->error('  No model selected. Aborting.');

            return self::FAILURE;
        }

        $this->writeEnv(['BEDROCK_DEFAULT_MODEL' => $modelId]);

        $this->newLine();
        $this->info("  ✓ Default model set to: <options=bold>{$modelId}</>");
        $this->line('  <fg=gray>BEDROCK_DEFAULT_MODEL has been written to your .env file.</>');
        $this->newLine();

        return self::SUCCESS;
    }

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
            $count       = count($grouped[$provider]);
            $activeCount = count(array_filter($grouped[$provider], fn ($m) => $m['is_active']));

            return "{$provider}  ({$activeCount} active / {$count} total)";
        }, $providers);

        $providerLabel = $this->choice('  Select a provider', $providerChoices, 0);
        $providerIndex = array_search($providerLabel, $providerChoices, true);
        $provider      = $providers[$providerIndex];
        $models        = $grouped[$provider];

        // ── Step 2: choose model ──────────────────────────────────
        $this->newLine();

        $maxNameLen   = max(array_map(fn ($m) => strlen($m['name']), $models));
        $modelChoices = array_map(function (array $model) use ($maxNameLen) {
            $status = $model['is_active'] ? '<fg=green>active</>' : '<fg=yellow>legacy</>';
            $ctx    = number_format($model['context_window'] / 1000) . 'k ctx';
            $name   = str_pad($model['name'], min($maxNameLen, 50));

            return "{$name}  │  {$model['model_id']}  │  {$ctx}  │  {$status}";
        }, $models);

        $modelLabel = $this->choice('  Select a model', $modelChoices, 0);
        $modelIndex = array_search($modelLabel, $modelChoices, true);

        return $models[$modelIndex]['model_id'];
    }

    // ─────────────────────────────────────────────────────────────────

    protected function writeEnv(array $values): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);

        foreach ($values as $key => $value) {
            $escapedValue = str_contains((string) $value, ' ') ? '"' . $value . '"' : $value;

            if (preg_match("/^{$key}=/m", $envContent)) {
                $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$escapedValue}", $envContent);
            } else {
                $envContent .= "\n{$key}={$escapedValue}";
            }
        }

        file_put_contents($envPath, $envContent);
    }
}
