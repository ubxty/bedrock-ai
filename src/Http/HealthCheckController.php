<?php

namespace Ubxty\BedrockAi\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Ubxty\BedrockAi\BedrockManager;

class HealthCheckController extends Controller
{
    /**
     * Check Bedrock connectivity and return health status.
     */
    public function __invoke(BedrockManager $manager): JsonResponse
    {
        if (! $manager->isConfigured()) {
            return response()->json([
                'status' => 'unconfigured',
                'message' => 'Bedrock credentials are not configured.',
            ], 503);
        }

        $result = $manager->testConnection();

        $statusCode = $result['success'] ? 200 : 503;

        return response()->json([
            'status' => $result['success'] ? 'healthy' : 'unhealthy',
            'message' => $result['message'],
            'response_time_ms' => $result['response_time'],
            'model_count' => $result['model_count'] ?? null,
        ], $statusCode);
    }
}
