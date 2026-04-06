<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class ModelsCommand extends Command
{
    protected $signature = 'bedrock:models
                            {--connection= : Connection name}
                            {--filter= : Filter models by name or ID}
                            {--provider= : Filter by model provider (e.g., anthropic, amazon, meta)}
                            {--legacy : Include legacy/deprecated models}
                            {--json : Output as JSON}';

    protected $description = 'List available foundation models from AWS Bedrock';

    public function handle(BedrockManager $manager): int
    {
        $connection = $this->option('connection');
        $filter = $this->option('filter');
        $providerFilter = $this->option('provider');

        if (! $manager->isConfigured($connection)) {
            $this->error('Bedrock is not configured. Run `php artisan bedrock:configure` first.');

            return 1;
        }

        $this->info('Fetching models from AWS Bedrock...');

        try {
            $models = $manager->fetchModels($connection);
        } catch (\Exception $e) {
            $this->error('Failed to fetch models: ' . $e->getMessage());

            return 1;
        }

        if (empty($models)) {
            $this->warn('No models found.');

            return 0;
        }

        // Apply filters
        if ($filter) {
            $models = array_filter($models, function ($model) use ($filter) {
                return str_contains(strtolower($model['model_id']), strtolower($filter))
                    || str_contains(strtolower($model['name']), strtolower($filter));
            });
        }

        if ($providerFilter) {
            $models = array_filter($models, function ($model) use ($providerFilter) {
                return str_contains(strtolower($model['model_id']), strtolower($providerFilter))
                    || str_contains(strtolower($model['provider'] ?? ''), strtolower($providerFilter));
            });
        }

        $models = array_values($models);

        // Filter legacy models unless --legacy is passed
        $showLegacy = $this->option('legacy');
        if (! $showLegacy) {
            $models = array_values(array_filter($models, fn ($m) => $m['is_active']));
        }

        if ($this->option('json')) {
            $this->line(json_encode($models, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('Found ' . count($models) . ($showLegacy ? ' models (including legacy).' : ' active models.'));
        if (! $showLegacy) {
            $this->line('<fg=gray>  Pass --legacy to include deprecated models.</>');  
        }
        $this->newLine();

        // Group by provider prefix
        $grouped = [];
        foreach ($models as $model) {
            $prefix = explode('.', $model['model_id'])[0] ?? 'other';
            $grouped[$prefix][] = $model;
        }

        ksort($grouped);

        // Remove providers that have been globally disabled in config.
        $disabled = array_map(
            'strtolower',
            array_filter((array) (config('bedrock.providers.disabled_providers', [])))
        );

        if (! empty($disabled)) {
            $grouped = array_filter(
                $grouped,
                fn (string $provider) => ! in_array(strtolower($provider), $disabled, true),
                ARRAY_FILTER_USE_KEY
            );
        }

        foreach ($grouped as $provider => $providerModels) {
            $this->info("  {$provider} (" . count($providerModels) . ' models)');

            $rows = array_map(function ($model) {
                $ctx = number_format($model['context_window'] / 1000) . 'k';

                $inputs = $model['input_modalities'] ?? ['text'];
                $inputTags = [];
                if (in_array('image', $inputs, true)) {
                    $inputTags[] = 'img';
                }
                if (in_array('document', $inputs, true)) {
                    $inputTags[] = 'pdf';
                }

                return [
                    $model['name'],
                    $model['model_id'],
                    $ctx,
                    implode(', ', $model['capabilities']),
                    ! empty($inputTags) ? implode(', ', $inputTags) : '—',
                    $model['is_active'] ? '✓' : '—',
                ];
            }, $providerModels);

            $this->table(
                ['Name', 'Model ID', 'Context', 'Output', 'Accepts', 'Active'],
                $rows
            );

            $this->newLine();
        }

        return 0;
    }
}
