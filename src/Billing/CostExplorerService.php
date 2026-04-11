<?php

namespace Ubxty\BedrockAi\Billing;

use Aws\CostExplorer\CostExplorerClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ubxty\BedrockAi\Exceptions\BedrockException;

class CostExplorerService
{
    protected ?CostExplorerClient $client = null;

    protected string $accessKey;

    protected string $secretKey;

    protected string $region;

    protected int $cacheTtl;

    public function __construct(string $accessKey, string $secretKey, string $region = 'us-east-1', int $cacheTtl = 3600)
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
            $config['cache_ttl'] ?? 3600
        );
    }

    /**
     * Test connection to AWS Cost Explorer.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $this->getClient()->getCostAndUsage([
                'TimePeriod' => [
                    'Start' => date('Y-m-01'),
                    'End' => date('Y-m-d'),
                ],
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost'],
                'Filter' => $this->bedrockFilter(),
            ]);

            return ['success' => true, 'message' => 'Connected to AWS Cost Explorer successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get Bedrock costs for a date range.
     *
     * @param  string  $start  Start date (Y-m-d)
     * @param  string  $end    End date (Y-m-d, exclusive)
     * @param  string  $granularity  DAILY or MONTHLY
     * @return array{results_by_time: array, total: array}
     */
    public function getBedrockCosts(string $start, string $end, string $granularity = 'DAILY'): array
    {
        $cacheKey = "bedrock_billing_costs_{$start}_{$end}_{$granularity}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($start, $end, $granularity) {
            return $this->fetchCosts($start, $end, $granularity);
        });
    }

    /**
     * Get daily Bedrock costs for the last N days.
     *
     * @return array{results_by_time: array, total: array}
     */
    public function getDailyCosts(int $days = 30): array
    {
        $end = date('Y-m-d');
        $start = date('Y-m-d', strtotime("-{$days} days"));

        return $this->getBedrockCosts($start, $end, 'DAILY');
    }

    /**
     * Get monthly Bedrock cost summary.
     *
     * @return array{results_by_time: array, total: array}
     */
    public function getMonthlySummary(int $months = 3): array
    {
        $end = date('Y-m-01', strtotime('+1 month'));
        $start = date('Y-m-01', strtotime("-{$months} months"));

        return $this->getBedrockCosts($start, $end, 'MONTHLY');
    }

    /**
     * Get Bedrock cost breakdown by usage type (input/output tokens, etc.).
     *
     * @param  string  $start  Start date (Y-m-d)
     * @param  string  $end    End date (Y-m-d, exclusive)
     * @return array{results_by_time: array, total: array}
     */
    public function getCostByUsageType(string $start, string $end): array
    {
        $cacheKey = "bedrock_billing_by_usage_{$start}_{$end}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($start, $end) {
            return $this->fetchCostsByUsageType($start, $end);
        });
    }

    /**
     * Get AWS cost forecast for Bedrock.
     *
     * @param  string  $start  Forecast start date (Y-m-d, must be today or future)
     * @param  string  $end    Forecast end date (Y-m-d)
     * @param  string  $granularity  DAILY or MONTHLY
     * @return array{total: array, forecast_by_time: array}
     */
    public function getCostForecast(string $start, string $end, string $granularity = 'MONTHLY'): array
    {
        $cacheKey = "bedrock_billing_forecast_{$start}_{$end}_{$granularity}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($start, $end, $granularity) {
            return $this->fetchCostForecast($start, $end, $granularity);
        });
    }

    /**
     * Fetch actual costs from AWS Cost Explorer API.
     */
    protected function fetchCosts(string $start, string $end, string $granularity): array
    {
        $client = $this->getClient();
        $results = [];
        $nextToken = null;

        do {
            $params = [
                'TimePeriod' => [
                    'Start' => $start,
                    'End' => $end,
                ],
                'Granularity' => $granularity,
                'Metrics' => ['UnblendedCost', 'UsageQuantity'],
                'Filter' => $this->bedrockFilter(),
            ];

            if ($nextToken) {
                $params['NextPageToken'] = $nextToken;
            }

            $result = $client->getCostAndUsage($params);

            foreach ($result['ResultsByTime'] ?? [] as $timePeriod) {
                $results[] = [
                    'start' => $timePeriod['TimePeriod']['Start'],
                    'end' => $timePeriod['TimePeriod']['End'],
                    'estimated' => $timePeriod['Estimated'] ?? false,
                    'cost' => [
                        'amount' => (float) ($timePeriod['Total']['UnblendedCost']['Amount'] ?? 0),
                        'unit' => $timePeriod['Total']['UnblendedCost']['Unit'] ?? 'USD',
                    ],
                    'usage' => [
                        'amount' => (float) ($timePeriod['Total']['UsageQuantity']['Amount'] ?? 0),
                        'unit' => $timePeriod['Total']['UsageQuantity']['Unit'] ?? '',
                    ],
                ];
            }

            $nextToken = $result['NextPageToken'] ?? null;
        } while ($nextToken);

        $totalCost = array_sum(array_column(array_column($results, 'cost'), 'amount'));

        Log::info('Bedrock billing data fetched', [
            'period' => "{$start} to {$end}",
            'granularity' => $granularity,
            'data_points' => count($results),
            'total_cost' => $totalCost,
        ]);

        return [
            'results_by_time' => $results,
            'total' => [
                'amount' => round($totalCost, 6),
                'unit' => 'USD',
            ],
        ];
    }

    /**
     * Fetch costs grouped by usage type.
     */
    protected function fetchCostsByUsageType(string $start, string $end): array
    {
        $client = $this->getClient();
        $results = [];
        $nextToken = null;

        do {
            $params = [
                'TimePeriod' => [
                    'Start' => $start,
                    'End' => $end,
                ],
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost', 'UsageQuantity'],
                'Filter' => $this->bedrockFilter(),
                'GroupBy' => [
                    [
                        'Key' => 'USAGE_TYPE',
                        'Type' => 'DIMENSION',
                    ],
                ],
            ];

            if ($nextToken) {
                $params['NextPageToken'] = $nextToken;
            }

            $result = $client->getCostAndUsage($params);

            foreach ($result['ResultsByTime'] ?? [] as $timePeriod) {
                $groups = [];
                foreach ($timePeriod['Groups'] ?? [] as $group) {
                    $usageType = $group['Keys'][0] ?? 'Unknown';
                    $groups[] = [
                        'usage_type' => $usageType,
                        'cost' => [
                            'amount' => (float) ($group['Metrics']['UnblendedCost']['Amount'] ?? 0),
                            'unit' => $group['Metrics']['UnblendedCost']['Unit'] ?? 'USD',
                        ],
                        'usage' => [
                            'amount' => (float) ($group['Metrics']['UsageQuantity']['Amount'] ?? 0),
                            'unit' => $group['Metrics']['UsageQuantity']['Unit'] ?? '',
                        ],
                    ];
                }

                $results[] = [
                    'start' => $timePeriod['TimePeriod']['Start'],
                    'end' => $timePeriod['TimePeriod']['End'],
                    'groups' => $groups,
                ];
            }

            $nextToken = $result['NextPageToken'] ?? null;
        } while ($nextToken);

        return [
            'results_by_time' => $results,
        ];
    }

    /**
     * Fetch cost forecast from AWS.
     */
    protected function fetchCostForecast(string $start, string $end, string $granularity): array
    {
        $client = $this->getClient();

        try {
            $result = $client->getCostForecast([
                'TimePeriod' => [
                    'Start' => $start,
                    'End' => $end,
                ],
                'Granularity' => $granularity,
                'Metric' => 'UNBLENDED_COST',
                'Filter' => $this->bedrockFilter(),
            ]);
        } catch (\Aws\CostExplorer\Exception\CostExplorerException $e) {
            if (str_contains($e->getAwsErrorMessage() ?? '', 'not enough data')) {
                return [
                    'total' => ['amount' => 0, 'unit' => 'USD'],
                    'forecast_by_time' => [],
                    'error' => 'Not enough historical data for forecast',
                ];
            }

            throw $e;
        }

        $forecastResults = [];
        foreach ($result['ForecastResultsByTime'] ?? [] as $forecast) {
            $forecastResults[] = [
                'start' => $forecast['TimePeriod']['Start'],
                'end' => $forecast['TimePeriod']['End'],
                'mean' => (float) ($forecast['MeanValue'] ?? 0),
                'lower_bound' => (float) ($forecast['PredictionIntervalLowerBound'] ?? 0),
                'upper_bound' => (float) ($forecast['PredictionIntervalUpperBound'] ?? 0),
            ];
        }

        return [
            'total' => [
                'amount' => (float) ($result['Total']['Amount'] ?? 0),
                'unit' => $result['Total']['Unit'] ?? 'USD',
            ],
            'forecast_by_time' => $forecastResults,
        ];
    }

    /**
     * Build the SERVICE filter for Amazon Bedrock.
     */
    protected function bedrockFilter(): array
    {
        return [
            'Dimensions' => [
                'Key' => 'SERVICE',
                'Values' => ['Amazon Bedrock'],
                'MatchOptions' => ['EQUALS'],
            ],
        ];
    }

    /**
     * Lazily create the CostExplorerClient.
     */
    protected function getClient(): CostExplorerClient
    {
        if (! $this->client) {
            if (empty($this->accessKey) || empty($this->secretKey)) {
                throw new BedrockException('AWS Cost Explorer credentials not configured. Cost Explorer requires IAM credentials (access key + secret), not bearer tokens.');
            }

            $this->client = new CostExplorerClient([
                'version' => '2017-10-25',
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
