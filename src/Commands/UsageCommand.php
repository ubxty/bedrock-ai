<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class UsageCommand extends Command
{
    protected $signature = 'bedrock:usage
                            {--days=30 : Number of days to fetch usage for}
                            {--json : Output as JSON}
                            {--daily : Show daily trend breakdown}';

    protected $description = 'View AWS Bedrock usage metrics from CloudWatch';

    public function handle(BedrockManager $manager): int
    {
        $days = (int) $this->option('days');

        $this->info('');
        $this->info('  ╔═══════════════════════════════════════════╗');
        $this->info('  ║   AWS Bedrock Usage Report                ║');
        $this->info('  ╚═══════════════════════════════════════════╝');
        $this->info('');

        // Test connection first
        $this->info("  Fetching usage data for the last {$days} days...");

        try {
            $usage = $manager->usage();
        } catch (\Exception $e) {
            $this->error('  Usage tracking not configured: ' . $e->getMessage());
            $this->line('  Set BEDROCK_USAGE_KEY and BEDROCK_USAGE_SECRET in your .env');

            return 1;
        }

        // Test connection
        $connTest = $usage->testConnection();
        if (! $connTest['success']) {
            $this->error('  CloudWatch connection failed: ' . $connTest['message']);

            return 1;
        }

        $aggregated = $usage->getAggregatedUsage($days);

        if (empty($aggregated)) {
            $this->warn('  No usage data found for the last ' . $days . ' days.');

            return 0;
        }

        if ($this->option('json')) {
            $this->line(json_encode($aggregated, JSON_PRETTY_PRINT));

            return 0;
        }

        // Models summary table
        $this->info('  Active Models');
        $this->line('  ─────────────────────────────────────────────');

        $rows = [];
        $totalInput = 0;
        $totalOutput = 0;
        $totalInvocations = 0;

        foreach ($aggregated as $modelId => $data) {
            $rows[] = [
                strlen($modelId) > 45 ? substr($modelId, 0, 42) . '...' : $modelId,
                number_format($data['input_tokens']),
                number_format($data['output_tokens']),
                number_format($data['invocations']),
                round($data['avg_latency_ms'], 0) . 'ms',
            ];

            $totalInput += $data['input_tokens'];
            $totalOutput += $data['output_tokens'];
            $totalInvocations += $data['invocations'];
        }

        $this->table(
            ['Model', 'Input Tokens', 'Output Tokens', 'Invocations', 'Avg Latency'],
            $rows
        );

        $this->newLine();
        $this->info('  Totals');
        $this->line('  ─────────────────────────────────────────────');
        $this->line('  Input Tokens:  ' . number_format($totalInput));
        $this->line('  Output Tokens: ' . number_format($totalOutput));
        $this->line('  Total Tokens:  ' . number_format($totalInput + $totalOutput));
        $this->line('  Invocations:   ' . number_format($totalInvocations));
        $this->newLine();

        // Daily trend
        if ($this->option('daily')) {
            $this->showDailyTrend($usage, $days, $aggregated);
        }

        return 0;
    }

    protected function showDailyTrend($usage, int $days, array $aggregated): void
    {
        $trend = $usage->getDailyTrend($days, $aggregated);

        $this->info('  Daily Trend');
        $this->line('  ─────────────────────────────────────────────');

        $rows = array_filter($trend, function ($day) {
            return $day['input_tokens'] > 0 || $day['output_tokens'] > 0;
        });

        if (empty($rows)) {
            $this->line('  No daily data with activity.');

            return;
        }

        $this->table(
            ['Date', 'Input Tokens', 'Output Tokens', 'Invocations'],
            array_map(function ($day) {
                return [
                    $day['date'],
                    number_format($day['input_tokens']),
                    number_format($day['output_tokens']),
                    number_format($day['invocations']),
                ];
            }, $rows)
        );
    }
}
