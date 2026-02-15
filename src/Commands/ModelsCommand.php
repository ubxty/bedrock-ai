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

        if ($this->option('json')) {
            $this->line(json_encode($models, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('Found ' . count($models) . ' models.');
        $this->newLine();

        // Group by provider prefix
        $grouped = [];
        foreach ($models as $model) {
            $prefix = explode('.', $model['model_id'])[0] ?? 'other';
            $grouped[$prefix][] = $model;
        }

        ksort($grouped);

        foreach ($grouped as $provider => $providerModels) {
            $this->info("  {$provider} (" . count($providerModels) . ' models)');

            $rows = array_map(function ($model) {
                return [
                    $model['name'],
                    strlen($model['model_id']) > 50 ? substr($model['model_id'], 0, 47) . '...' : $model['model_id'],
                    number_format($model['context_window']),
                    number_format($model['max_tokens']),
                    implode(', ', $model['capabilities']),
                    $model['is_active'] ? '✓' : '✗',
                ];
            }, $providerModels);

            $this->table(
                ['Name', 'Model ID', 'Context', 'Max Tokens', 'Capabilities', 'Active'],
                $rows
            );

            $this->newLine();
        }

        return 0;
    }
}
