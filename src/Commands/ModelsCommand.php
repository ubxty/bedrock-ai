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
            $grouped = $manager->getModelsGrouped($connection);
        } catch (\Exception $e) {
            $this->error('Failed to fetch models: ' . $e->getMessage());

            return 1;
        }

        if (empty($grouped)) {
            $this->warn('No models found.');

            return 0;
        }

        // Apply --filter and --provider on the grouped output
        if ($filter || $providerFilter) {
            foreach ($grouped as $provider => &$providerModels) {
                if ($providerFilter && ! str_contains(strtolower($provider), strtolower($providerFilter))) {
                    unset($grouped[$provider]);
                    continue;
                }
                if ($filter) {
                    $providerModels = array_filter($providerModels, function ($m) use ($filter) {
                        return str_contains(strtolower($m['model_id']), strtolower($filter))
                            || str_contains(strtolower($m['name']), strtolower($filter));
                    });
                }
                if (empty($providerModels)) {
                    unset($grouped[$provider]);
                }
            }
            unset($providerModels);
        }

        // Filter legacy models unless --legacy is passed
        $showLegacy = $this->option('legacy');
        if (! $showLegacy) {
            foreach ($grouped as $provider => &$providerModels) {
                $providerModels = array_values(array_filter($providerModels, fn ($m) => $m['is_active']));
                if (empty($providerModels)) {
                    unset($grouped[$provider]);
                }
            }
            unset($providerModels);
        }

        $totalModels = array_sum(array_map('count', $grouped));

        if ($this->option('json')) {
            $this->line(json_encode($grouped, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('Found ' . $totalModels . ($showLegacy ? ' models (including legacy).' : ' active models.'));
        if (! $showLegacy) {
            $this->line('<fg=gray>  Pass --legacy to include deprecated models.</>');  
        }
        $this->newLine();

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
