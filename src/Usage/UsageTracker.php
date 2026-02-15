<?php

namespace Ubxty\BedrockAi\Usage;

use Aws\CloudWatch\CloudWatchClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ubxty\BedrockAi\Exceptions\BedrockException;

class UsageTracker
{
    protected ?CloudWatchClient $client = null;

    protected string $accessKey;

    protected string $secretKey;

    protected string $region;

    protected int $cacheTtl;

    /** @var array<string, array> */
    protected array $modelUsageCache = [];

    protected ?array $activeModelsCache = null;

    public function __construct(string $accessKey, string $secretKey, string $region = 'us-east-1', int $cacheTtl = 900)
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region = $region;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Create from config array.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            $config['aws_key'] ?? '',
            $config['aws_secret'] ?? '',
            $config['region'] ?? 'us-east-1',
            $config['cache_ttl'] ?? 900
        );
    }

    /**
     * Get list of all models that have CloudWatch metrics.
     *
     * @return array<string>
     */
    public function getActiveModels(): array
    {
        if ($this->activeModelsCache !== null) {
            return $this->activeModelsCache;
        }

        $cacheKey = 'bedrock_ai_active_models_' . md5($this->accessKey);

        $this->activeModelsCache = Cache::remember($cacheKey, 3600, function () {
            $models = [];

            try {
                $result = $this->getClient()->listMetrics([
                    'Namespace' => 'AWS/Bedrock',
                    'MetricName' => 'Invocations',
                ]);

                foreach ($result['Metrics'] as $metric) {
                    foreach ($metric['Dimensions'] as $dimension) {
                        if ($dimension['Name'] === 'ModelId') {
                            $models[] = $dimension['Value'];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to list Bedrock models from CloudWatch', ['error' => $e->getMessage()]);
            }

            return array_unique($models);
        });

        return $this->activeModelsCache;
    }

    /**
     * Get usage statistics for a specific model.
     *
     * @return array{InputTokenCount: array, OutputTokenCount: array, Invocations: array, InvocationLatency: array}
     */
    public function getModelUsage(string $modelId, int $days = 7): array
    {
        $cacheKey = "{$modelId}_{$days}";
        if (isset($this->modelUsageCache[$cacheKey])) {
            return $this->modelUsageCache[$cacheKey];
        }

        $endTime = Carbon::now();
        $startTime = Carbon::now()->subDays($days);

        $metrics = [
            'InputTokenCount' => [],
            'OutputTokenCount' => [],
            'Invocations' => [],
            'InvocationLatency' => [],
        ];

        try {
            $metricNames = array_keys($metrics);
            $queries = [];

            foreach ($metricNames as $index => $metricName) {
                $queries[] = [
                    'Id' => 'm' . $index,
                    'MetricStat' => [
                        'Metric' => [
                            'Namespace' => 'AWS/Bedrock',
                            'MetricName' => $metricName,
                            'Dimensions' => [
                                ['Name' => 'ModelId', 'Value' => $modelId],
                            ],
                        ],
                        'Period' => 86400,
                        'Stat' => $metricName === 'InvocationLatency' ? 'Average' : 'Sum',
                    ],
                    'ReturnData' => true,
                ];
            }

            $result = $this->getClient()->getMetricData([
                'MetricDataQueries' => $queries,
                'StartTime' => $startTime->toIso8601String(),
                'EndTime' => $endTime->toIso8601String(),
            ]);

            foreach ($result['MetricDataResults'] as $metricResult) {
                $metricIndex = (int) substr($metricResult['Id'], 1);
                $metricName = $metricNames[$metricIndex];

                foreach ($metricResult['Timestamps'] as $i => $timestamp) {
                    $date = Carbon::parse($timestamp)->format('Y-m-d');
                    $metrics[$metricName][$date] = $metricResult['Values'][$i] ?? 0;
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to get model usage', ['model' => $modelId, 'error' => $e->getMessage()]);
        }

        $this->modelUsageCache[$cacheKey] = $metrics;

        return $metrics;
    }

    /**
     * Get aggregated usage across all models.
     *
     * @return array<string, array{model_id: string, input_tokens: float, output_tokens: float, total_tokens: float, invocations: float, avg_latency_ms: float, daily_breakdown: array}>
     */
    public function getAggregatedUsage(int $days = 30): array
    {
        $cacheKey = "bedrock_ai_usage_{$days}d_" . md5($this->accessKey);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($days) {
            $models = $this->getActiveModels();
            $usage = [];

            foreach ($models as $modelId) {
                $modelUsage = $this->getModelUsage($modelId, $days);

                $totalInput = array_sum($modelUsage['InputTokenCount']);
                $totalOutput = array_sum($modelUsage['OutputTokenCount']);
                $totalInvocations = array_sum($modelUsage['Invocations']);
                $latencyValues = array_filter($modelUsage['InvocationLatency']);
                $avgLatency = count($latencyValues) > 0
                    ? array_sum($latencyValues) / count($latencyValues)
                    : 0;

                $usage[$modelId] = [
                    'model_id' => $modelId,
                    'input_tokens' => $totalInput,
                    'output_tokens' => $totalOutput,
                    'total_tokens' => $totalInput + $totalOutput,
                    'invocations' => $totalInvocations,
                    'avg_latency_ms' => round($avgLatency, 2),
                    'daily_breakdown' => $modelUsage,
                ];
            }

            return $usage;
        });
    }

    /**
     * Calculate estimated costs from usage data and a pricing map.
     *
     * @param  array  $usage  Aggregated usage from getAggregatedUsage()
     * @param  array  $pricingMap  Map of model_id => {input_price: float, output_price: float}
     * @return array{models: array, total_cost: float, total_input_tokens: float, total_output_tokens: float, total_invocations: float}
     */
    public function calculateCosts(array $usage, array $pricingMap = []): array
    {
        $costs = [];
        $totalCost = 0;

        foreach ($usage as $modelId => $data) {
            $cleanId = $this->extractModelName($modelId);
            $pricing = $this->findPricing($cleanId, $pricingMap);

            $inputCost = ($data['input_tokens'] / 1000) * $pricing['input_price'];
            $outputCost = ($data['output_tokens'] / 1000) * $pricing['output_price'];
            $modelCost = $inputCost + $outputCost;

            $costs[$modelId] = [
                'model_id' => $modelId,
                'input_tokens' => $data['input_tokens'],
                'output_tokens' => $data['output_tokens'],
                'invocations' => $data['invocations'],
                'input_price' => $pricing['input_price'],
                'output_price' => $pricing['output_price'],
                'input_cost' => round($inputCost, 6),
                'output_cost' => round($outputCost, 6),
                'total_cost' => round($modelCost, 6),
                'avg_latency_ms' => $data['avg_latency_ms'],
            ];

            $totalCost += $modelCost;
        }

        uasort($costs, fn ($a, $b) => $b['total_cost'] <=> $a['total_cost']);

        return [
            'models' => $costs,
            'total_cost' => round($totalCost, 4),
            'total_input_tokens' => array_sum(array_column($costs, 'input_tokens')),
            'total_output_tokens' => array_sum(array_column($costs, 'output_tokens')),
            'total_invocations' => array_sum(array_column($costs, 'invocations')),
        ];
    }

    /**
     * Get daily usage trend for charts.
     *
     * @return array<int, array{date: string, input_tokens: float, output_tokens: float, invocations: float}>
     */
    public function getDailyTrend(int $days = 30, ?array $aggregatedUsage = null): array
    {
        $usage = $aggregatedUsage ?? $this->getAggregatedUsage($days);
        $dailyData = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dailyData[$date] = [
                'date' => $date,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'invocations' => 0,
            ];
        }

        foreach ($usage as $modelData) {
            $breakdown = $modelData['daily_breakdown'] ?? [];

            foreach (['InputTokenCount' => 'input_tokens', 'OutputTokenCount' => 'output_tokens', 'Invocations' => 'invocations'] as $metric => $field) {
                foreach ($breakdown[$metric] ?? [] as $date => $value) {
                    if (isset($dailyData[$date])) {
                        $dailyData[$date][$field] += $value;
                    }
                }
            }
        }

        return array_values($dailyData);
    }

    /**
     * Test CloudWatch connection.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $this->getClient()->listMetrics([
                'Namespace' => 'AWS/Bedrock',
                'MaxResults' => 1,
            ]);

            return ['success' => true, 'message' => 'CloudWatch connection successful'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    protected function extractModelName(string $modelId): string
    {
        $parts = explode('.', $modelId);

        if ($parts[0] === 'global' && count($parts) > 1) {
            array_shift($parts);
        }

        return implode('.', $parts);
    }

    protected function findPricing(string $modelId, array $pricingMap): array
    {
        // Exact match first
        if (isset($pricingMap[$modelId])) {
            return $pricingMap[$modelId];
        }

        // Partial match
        foreach ($pricingMap as $key => $pricing) {
            if (str_contains($modelId, $key) || str_contains($key, $modelId)) {
                return $pricing;
            }
        }

        // Fallback
        return ['input_price' => 0, 'output_price' => 0];
    }

    protected function getClient(): CloudWatchClient
    {
        if (! $this->client) {
            if (empty($this->accessKey) || empty($this->secretKey)) {
                throw new BedrockException('CloudWatch credentials not configured.');
            }

            $this->client = new CloudWatchClient([
                'version' => 'latest',
                'region' => $this->region,
                'credentials' => [
                    'key' => $this->accessKey,
                    'secret' => $this->secretKey,
                ],
            ]);
        }

        return $this->client;
    }
}
