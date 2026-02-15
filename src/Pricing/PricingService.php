<?php

namespace Ubxty\BedrockAi\Pricing;

use Aws\Pricing\PricingClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ubxty\BedrockAi\Exceptions\BedrockException;

class PricingService
{
    protected ?PricingClient $client = null;

    protected string $accessKey;

    protected string $secretKey;

    protected int $cacheTtl;

    public function __construct(string $accessKey, string $secretKey, int $cacheTtl = 86400)
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
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
            $config['cache_ttl'] ?? 86400
        );
    }

    /**
     * Fetch Bedrock pricing (cached).
     *
     * @return array<string, array{model_id: string, model_name: string, provider: string, input_price: float, output_price: float}>
     */
    public function getPricing(): array
    {
        return Cache::remember('bedrock_ai_pricing', $this->cacheTtl, function () {
            return $this->fetchPricing();
        });
    }

    /**
     * Force-refresh pricing from AWS.
     */
    public function refreshPricing(): array
    {
        Cache::forget('bedrock_ai_pricing');

        return $this->getPricing();
    }

    /**
     * Test connection to AWS Pricing API.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $this->getClient()->describeServices([
                'ServiceCode' => 'AmazonBedrock',
            ]);

            return ['success' => true, 'message' => 'Connected to AWS Pricing API successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch pricing directly from AWS Pricing API.
     */
    protected function fetchPricing(): array
    {
        $pricing = [];
        $client = $this->getClient();
        $nextToken = null;
        $pageCount = 0;
        $maxPages = 50;

        do {
            $params = ['ServiceCode' => 'AmazonBedrock', 'MaxResults' => 100];

            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            $result = $client->getProducts($params);
            $pageCount++;

            foreach ($result['PriceList'] as $priceJson) {
                $priceData = json_decode($priceJson, true);
                $parsed = $this->parsePriceData($priceData);

                if ($parsed) {
                    $modelId = $parsed['model_id'];

                    if (! isset($pricing[$modelId])) {
                        $pricing[$modelId] = $parsed;
                    } else {
                        if ($parsed['input_price'] > 0) {
                            $pricing[$modelId]['input_price'] = $parsed['input_price'];
                        }
                        if ($parsed['output_price'] > 0) {
                            $pricing[$modelId]['output_price'] = $parsed['output_price'];
                        }
                    }
                }
            }

            $nextToken = $result['NextToken'] ?? null;
        } while ($nextToken && $pageCount < $maxPages);

        Log::info('Bedrock AI pricing fetched', ['models' => count($pricing), 'pages' => $pageCount]);

        return $pricing;
    }

    protected function parsePriceData(array $priceData): ?array
    {
        $attributes = $priceData['product']['attributes'] ?? [];
        $terms = $priceData['terms'] ?? [];

        $usageType = $attributes['usagetype'] ?? '';
        $feature = $attributes['feature'] ?? '';
        $inferenceType = strtolower($attributes['inferenceType'] ?? '');

        // Skip guardrails, provisioned throughput, etc.
        if (str_contains(strtolower($usageType), 'guardrail')
            || str_contains(strtolower($usageType), 'provisioned')
            || str_contains(strtolower($feature), 'provisioned')) {
            return null;
        }

        $modelName = $attributes['model'] ?? '';
        $provider = $attributes['provider'] ?? '';
        $region = $attributes['regionCode'] ?? '';

        if ($region && $region !== 'us-east-1') {
            return null;
        }

        if (! $modelName) {
            return null;
        }

        $modelId = ModelIdNormalizer::normalize($modelName, $provider, $attributes);

        if (! $modelId) {
            return null;
        }

        $isInput = str_contains($inferenceType, 'input') || str_contains($inferenceType, 'request');
        $isOutput = str_contains($inferenceType, 'output') || str_contains($inferenceType, 'response');

        if (! $isInput && ! $isOutput) {
            return null;
        }

        $price = 0;
        $unit = '';
        if (! empty($terms['OnDemand'])) {
            foreach ($terms['OnDemand'] as $term) {
                foreach ($term['priceDimensions'] ?? [] as $dimension) {
                    $price = (float) ($dimension['pricePerUnit']['USD'] ?? 0);
                    $unit = $dimension['unit'] ?? '';
                    break 2;
                }
            }
        }

        return [
            'model_id' => $modelId,
            'model_name' => $modelName,
            'provider' => $provider,
            'region' => $region,
            'input_price' => $isInput ? $price : 0,
            'output_price' => $isOutput ? $price : 0,
            'unit' => $unit,
        ];
    }

    protected function getClient(): PricingClient
    {
        if (! $this->client) {
            if (empty($this->accessKey) || empty($this->secretKey)) {
                throw new BedrockException('AWS Pricing API credentials not configured.');
            }

            $this->client = new PricingClient([
                'version' => 'latest',
                'region' => 'us-east-1',
                'credentials' => [
                    'key' => $this->accessKey,
                    'secret' => $this->secretKey,
                ],
            ]);
        }

        return $this->client;
    }
}
