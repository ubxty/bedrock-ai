<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class DefaultModelCommand extends Command
{
    use WritesEnvFile;
    protected $signature = 'bedrock:default-model
                            {--show    : Show current default models}
                            {--reset   : Clear both default models}
                            {--legacy  : Include legacy/deprecated models in picker}
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

        $currentChat  = $manager->defaultModel();
        $currentImage = $manager->defaultImageModel();

        // Show what is already configured
        $this->line('  Current defaults:');
        $this->line('  Chat  → ' . ($currentChat  ? "<fg=cyan>{$currentChat}</>"  : '<fg=yellow>not set</>'));
        $this->line('  Image → ' . ($currentImage ? "<fg=cyan>{$currentImage}</>" : '<fg=yellow>not set</>'));
        $this->newLine();

        // Ask which type(s) to configure
        $setChat  = ! $currentChat  || $this->confirm('  Update default <options=bold>chat</> model?',  ! $currentChat);
        $setImage = ! $currentImage || $this->confirm('  Update default <options=bold>image</> model?', ! $currentImage);

        if (! $setChat && ! $setImage) {
            $this->line('  Nothing to update.');

            return self::SUCCESS;
        }

        $chatModelId  = $currentChat;
        $imageModelId = $currentImage;

        // ── Chat model ────────────────────────────────────────────
        if ($setChat) {
            $this->newLine();
            $this->line('  <options=bold>─── Default Chat Model ──────────────────────────────────</>');
            $this->newLine();

            $chatModelId = $this->pickModel($manager, $connection, null, 'chat');

            if (! $chatModelId) {
                $this->error('  No chat model selected. Aborting.');

                return self::FAILURE;
            }

            // Test before committing
            if ($this->confirm("  Test <options=bold>{$chatModelId}</> before setting as default?", true)) {
                if (! $this->testModel($manager, $connection, $chatModelId, 'chat')) {
                    if (! $this->confirm('  Test failed. Set this model as default anyway?', false)) {
                        $this->warn('  Chat model not saved.');
                        $chatModelId = $currentChat; // revert
                    }
                }
            }
        }

        // ── Image model ───────────────────────────────────────────
        if ($setImage) {
            $this->newLine();
            $this->line('  <options=bold>─── Default Image Model ─────────────────────────────────</>');
            $this->line('  <fg=gray>Only models with image-input capability are shown.</>');
            $this->newLine();

            $imageModelId = $this->pickModel($manager, $connection, 'image', 'image');

            if ($imageModelId) {
                if ($this->confirm("  Test <options=bold>{$imageModelId}</> before setting as default?", true)) {
                    if (! $this->testModel($manager, $connection, $imageModelId, 'image')) {
                        if (! $this->confirm('  Test failed. Set this model as default anyway?', false)) {
                            $this->warn('  Image model not saved.');
                            $imageModelId = $currentImage; // revert
                        }
                    }
                }
            }
        }

        // ── Write env ─────────────────────────────────────────────
        $this->writeEnv([
            'BEDROCK_DEFAULT_MODEL'       => $chatModelId  ?? '',
            'BEDROCK_DEFAULT_IMAGE_MODEL' => $imageModelId ?? '',
        ]);

        $this->newLine();
        $this->info('  ✓ Default models saved:');
        $this->line('    Chat  → <options=bold>' . ($chatModelId  ?: '(none)') . '</>');
        $this->line('    Image → <options=bold>' . ($imageModelId ?: '(none)') . '</>');
        $this->line('  <fg=gray>Written to your .env file.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Test the model with a quick invocation. Returns true on success.
     */
    protected function testModel(BedrockManager $manager, ?string $connection, string $modelId, string $type): bool
    {
        $this->line("  <options=bold>Testing {$modelId}...</>");

        try {
            if ($type === 'image') {
                // Image models expect an image input; we test with the Converse API
                // using a plain text message to confirm connectivity.
                $result = $manager->converse(
                    $modelId,
                    [['role' => 'user', 'content' => 'Reply with just the word: OK']],
                    '',
                    50,
                    0.1,
                    $connection,
                );
                $preview = trim($result['response'] ?? '');
            } else {
                $result = $manager->invoke(
                    $modelId,
                    'You are a helpful assistant.',
                    'Reply with just the word: OK',
                    50,
                    0.1,
                    null,
                    $connection,
                );
                $preview = trim($result['response'] ?? '');
            }

            $this->info("  ✓ Model responded: <fg=cyan>\"{$preview}\"</> ({$result['latency_ms']}ms, {$result['total_tokens']} tokens)");

            return true;
        } catch (\Throwable $e) {
            $this->error('  ✗ Test failed: ' . $e->getMessage());

            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * @param string|null $capability  Capability filter: 'image' shows only image-capable models.
     * @param string|null $context     Provider filter context: 'chat' or 'image' (uses scoped disabled_providers).
     */
    protected function pickModel(BedrockManager $manager, ?string $connection, ?string $capability = null, ?string $context = null): ?string
    {
        $this->line('  <options=bold>Fetching available models...</>');
        $showLegacy = $this->option('legacy');

        try {
            $grouped = $manager->getModelsGrouped($connection, $context);
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
                        $grouped = $manager->getModelsGrouped($connection, $context);
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

        // Filter legacy models unless --legacy is passed
        if (! $showLegacy) {
            foreach ($grouped as $provider => $models) {
                $grouped[$provider] = array_values(
                    array_filter($models, fn ($m) => $m['is_active'])
                );
            }
            $grouped = array_filter($grouped, fn ($models) => ! empty($models));
        }

        $totalModels = array_sum(array_map('count', $grouped));

        if (! $showLegacy) {
            $this->info("  Found {$totalModels} active models across " . count($grouped) . ' providers.');
            $this->line('  <fg=gray>Pass --legacy to include deprecated models.</>');
        } else {
            $this->info("  Found {$totalModels} models (including legacy) across " . count($grouped) . ' providers.');
        }

        $this->newLine();

        // ── Step 1: choose provider ───────────────────────────────
        $providers = array_keys($grouped);
        $providerChoices = array_map(function (string $provider) use ($grouped) {
            $count = count($grouped[$provider]);

            return "{$provider} ({$count} models)";
        }, $providers);

        $providerLabel = $this->choice('  Select a provider', $providerChoices, 0);
        $providerIndex = array_search($providerLabel, $providerChoices, true);
        $provider      = $providers[$providerIndex];
        $models        = $grouped[$provider];

        // ── Step 2: choose model ──────────────────────────────────
        $this->newLine();

        $nameCounts   = array_count_values(array_column($models, 'name'));
        $modelChoices = array_map(function (array $model) use ($nameCounts) {
            $ctx   = number_format($model['context_window'] / 1000) . 'k';
            $label = "{$model['name']} — {$ctx} context";

            $inputs = $model['input_modalities'] ?? ['text'];
            $tags = [];
            if (in_array('image', $inputs, true)) {
                $tags[] = 'img';
            }
            if (in_array('document', $inputs, true)) {
                $tags[] = 'pdf';
            }
            if (! empty($tags)) {
                $label .= '  [' . implode(', ', $tags) . ']';
            }

            if ($nameCounts[$model['name']] > 1) {
                $shortId = preg_replace('/^[^.]+\./', '', $model['model_id']);
                $label .= "  ({$shortId})";
            }

            return $label;
        }, $models);

        $modelLabel = $this->choice('  Select a model', $modelChoices, 0);
        $modelIndex = array_search($modelLabel, $modelChoices, true);
        $selected   = $models[$modelIndex];

        $this->line("  <fg=gray>Model ID: {$selected['model_id']}</>");

        return $selected['model_id'];
    }
}
