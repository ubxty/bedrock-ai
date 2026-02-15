<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class PricingCommand extends Command
{
    protected $signature = 'bedrock:pricing
                            {--filter= : Filter models by name or ID}
                            {--refresh : Force refresh from AWS (ignore cache)}
                            {--json : Output as JSON}';

    protected $description = 'Fetch and display real-time AWS Bedrock model pricing';

    public function handle(BedrockManager $manager): int
    {
        $this->info('');
        $this->info('  ╔═══════════════════════════════════════════╗');
        $this->info('  ║   AWS Bedrock Model Pricing               ║');
        $this->info('  ╚═══════════════════════════════════════════╝');
        $this->info('');

        try {
            $pricingService = $manager->pricing();
        } catch (\Exception $e) {
            $this->error('  Pricing API not configured: ' . $e->getMessage());
            $this->line('  Set BEDROCK_PRICING_KEY and BEDROCK_PRICING_SECRET in your .env');

            return 1;
        }

        // Test connection
        $connTest = $pricingService->testConnection();
        if (! $connTest['success']) {
            $this->error('  Pricing API connection failed: ' . $connTest['message']);

            return 1;
        }

        $this->info('  Fetching pricing from AWS Pricing API...');

        $pricing = $this->option('refresh')
            ? $pricingService->refreshPricing()
            : $pricingService->getPricing();

        if (empty($pricing)) {
            $this->warn('  No pricing data found.');

            return 0;
        }

        // Apply filter
        $filter = $this->option('filter');
        if ($filter) {
            $pricing = array_filter($pricing, function ($model, $key) use ($filter) {
                return str_contains(strtolower($key), strtolower($filter))
                    || str_contains(strtolower($model['model_name'] ?? ''), strtolower($filter))
                    || str_contains(strtolower($model['provider'] ?? ''), strtolower($filter));
            }, ARRAY_FILTER_USE_BOTH);
        }

        if ($this->option('json')) {
            $this->line(json_encode($pricing, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('  Found ' . count($pricing) . ' models with pricing.');
        $this->newLine();

        // Group by provider
        $grouped = [];
        foreach ($pricing as $modelId => $model) {
            $provider = $model['provider'] ?? 'Other';
            $grouped[$provider][] = array_merge($model, ['model_id' => $modelId]);
        }

        ksort($grouped);

        foreach ($grouped as $provider => $models) {
            $this->info("  {$provider}");

            // Sort by model name
            usort($models, fn ($a, $b) => ($a['model_name'] ?? '') <=> ($b['model_name'] ?? ''));

            $rows = array_map(function ($model) {
                $inputPrice = $model['input_price'] ?? 0;
                $outputPrice = $model['output_price'] ?? 0;

                return [
                    strlen($model['model_name'] ?? '') > 35
                        ? substr($model['model_name'], 0, 32) . '...'
                        : ($model['model_name'] ?? '-'),
                    strlen($model['model_id']) > 40
                        ? substr($model['model_id'], 0, 37) . '...'
                        : $model['model_id'],
                    $inputPrice > 0 ? '$' . number_format($inputPrice, 6) : '-',
                    $outputPrice > 0 ? '$' . number_format($outputPrice, 6) : '-',
                ];
            }, $models);

            $this->table(
                ['Model', 'Model ID', 'Input $/1K', 'Output $/1K'],
                $rows
            );
            $this->newLine();
        }

        return 0;
    }
}
