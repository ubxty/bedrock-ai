<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class DefaultModelCommand extends Command
{
    protected $signature = 'bedrock:default-model
                            {--show    : Show current default models}
                            {--reset   : Clear both default models}
                            {--connection= : Use a specific connection}';

    protected $description = 'Set or show the default Bedrock chat and image models';

    public function handle(BedrockManager $manager): int
    {
        $connection = $this->option('connection') ?: null;

        // ── --show ────────────────────────────────────────────────
        if ($this->option('show')) {
            $chat  = $manager->defaultModel();
            $image = $manager->defaultImageModel();
            $this->info('  Default Models');
            $this->line('  ─────────────────────────────────────────────');
            $this->line('  Chat  (BEDROCK_DEFAULT_MODEL):       ' . ($chat  ?: '<fg=yellow>not set</>'));
            $this->line('  Image (BEDROCK_DEFAULT_IMAGE_MODEL): ' . ($image ?: '<fg=yellow>not set</>'));
            $this->newLine();

            return self::SUCCESS;
        }

        // ── --reset ───────────────────────────────────────────────
        if ($this->option('reset')) {
            $this->writeEnv(['BEDROCK_DEFAULT_MODEL' => '', 'BEDROCK_DEFAULT_IMAGE_MODEL' => '']);
            $this->info('  Default chat and image models cleared.');

            return self::SUCCESS;
        }

        // ── Interactive picker ────────────────────────────────────
        $this->info('');
        $this->info('  ╔═══════════════════════════════════════════╗');
        $this->info('  ║   Set Default Bedrock Models              ║');
        $this->info('  ╚═══════════════════════════════════════════╝');
        $this->info('');

        // ── Chat model ────────────────────────────────────────────
        $currentChat = $manager->defaultModel();
        $this->line('  <options=bold>Step 1: Default Chat Model</>');
        if ($currentChat) {
            $this->line("  Current: <fg=cyan>{$currentChat}</>");
        }
        $this->newLine();

        $chatModelId = $this->pickModel($manager, $connection);

        if (! $chatModelId) {
            $this->error('  No chat model selected. Aborting.');

            return self::FAILURE;
        }

        // ── Image model ───────────────────────────────────────────
        $this->newLine();
        $this->line('  <options=bold>Step 2: Default Image Model</>');
        $currentImage = $manager->defaultImageModel();
        if ($currentImage) {
            $this->line("  Current: <fg=cyan>{$currentImage}</>");
        }
        $this->line('  <fg=gray>Only models with image-input capability are shown.</>');
        $this->newLine();

        $imageModelId = $this->pickModel($manager, $connection, 'image');

        // ── Write env ─────────────────────────────────────────────
        $this->writeEnv([
            'BEDROCK_DEFAULT_MODEL'       => $chatModelId,
            'BEDROCK_DEFAULT_IMAGE_MODEL' => $imageModelId ?? '',
        ]);

        $this->newLine();
        $this->info("  ✓ Default chat model:  <options=bold>{$chatModelId}</>");
        $this->info('  ✓ Default image model: <options=bold>' . ($imageModelId ?: '(none)') . '</>');
        $this->line('  <fg=gray>Written to your .env file.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * @param string|null $capability  Filter to models supporting this capability (e.g. 'image').
     *                                 Pass null to show all models.
     */
    protected function pickModel(BedrockManager $manager, ?string $connection, ?string $capability = null): ?string
    {
        $this->line('  <options=bold>Fetching available models...</>');

        try {
            $grouped = $manager->getModelsGrouped($connection);
        } catch (\Throwable $e) {
            $this->error('  ✗ Could not load models: ' . $e->getMessage());

            return $this->ask('  Enter model ID manually');
        }

        if (empty($grouped)) {
            $this->warn('  No models found in the local database.');
            $this->line('  <fg=gray>This usually means models have not been synced yet.</>\n');

            if ($this->confirm('  Sync models from AWS now?', true)) {
                $this->line('  Syncing...');
                try {
                    $count = $manager->syncModels($connection);
                    if ($count > 0) {
                        $this->info("  ✓ Synced {$count} models.");
                        $grouped = $manager->getModelsGrouped($connection);
                    } else {
                        $this->warn('  Sync returned 0 models (bearer tokens cannot access the model listing endpoint).');
                        $this->line('  <fg=gray>Enter the model ID manually instead — e.g. amazon.nova-lite-v1:0</>');
                    }
                } catch (\Throwable $e) {
                    $this->error('  Sync failed: ' . $e->getMessage());
                }
            }

            if (empty($grouped)) {
                return $this->ask('  Enter model ID manually');
            }
        }

        // Filter by capability if requested
        if ($capability !== null) {
            foreach ($grouped as $provider => $models) {
                $grouped[$provider] = array_values(
                    array_filter($models, fn ($m) => in_array($capability, $m['capabilities'], true))
                );
            }
            $grouped = array_filter($grouped, fn ($models) => ! empty($models));

            if (empty($grouped)) {
                $this->warn("  No models found with '{$capability}' capability.");
                $this->line('  <fg=gray>You can enter a model ID manually or skip (leave blank).</>');

                return $this->ask('  Enter model ID manually (or leave blank to skip)') ?: null;
            }
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
