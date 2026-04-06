<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The default Bedrock connection to use. This corresponds to a key in the
    | "connections" array below. You may define as many connections as needed.
    |
    */
    'default' => env('BEDROCK_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Bedrock Connections
    |--------------------------------------------------------------------------
    |
    | Each connection defines AWS credentials, region, and optional settings
    | for invoking Bedrock models. You can define multiple connections and
    | switch between them at runtime.
    |
    | "keys" supports multiple AWS credential sets for automatic failover.
    | Each key set can have a label, access key, secret, and region.
    |
    */
    'connections' => [
        'default' => [
            'keys' => [
                [
                    'label' => env('BEDROCK_KEY_LABEL', 'Primary'),
                    'auth_mode' => env('BEDROCK_AUTH_MODE', 'iam'), // 'iam' or 'bearer'
                    'aws_key' => env('BEDROCK_AWS_KEY', env('AWS_ACCESS_KEY_ID', '')),
                    'aws_secret' => env('BEDROCK_AWS_SECRET', env('AWS_SECRET_ACCESS_KEY', '')),
                    'bearer_token' => env('BEDROCK_BEARER_TOKEN', ''),
                    'region' => env('BEDROCK_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the client handles rate limiting and transient errors.
    |
    */
    'retry' => [
        'max_retries' => env('BEDROCK_MAX_RETRIES', 3),
        'base_delay' => env('BEDROCK_RETRY_DELAY', 2), // seconds, doubles each retry
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost Limits
    |--------------------------------------------------------------------------
    |
    | Set daily and monthly spending limits. When exceeded, API calls will
    | throw a CostLimitExceededException. Set to null to disable.
    |
    */
    'limits' => [
        'daily' => env('BEDROCK_DAILY_LIMIT', null),   // e.g., 10.00
        'monthly' => env('BEDROCK_MONTHLY_LIMIT', null), // e.g., 300.00
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing API Credentials
    |--------------------------------------------------------------------------
    |
    | Separate credentials for the AWS Pricing API. If not set, falls back to
    | the default connection's first key credentials. The Pricing API is only
    | available in us-east-1 and ap-south-1.
    |
    */
    'pricing' => [
        'aws_key' => env('BEDROCK_PRICING_KEY', ''),
        'aws_secret' => env('BEDROCK_PRICING_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Tracking (CloudWatch)
    |--------------------------------------------------------------------------
    |
    | Credentials for reading Bedrock usage metrics from CloudWatch.
    | Falls back to the default connection's first key if not set.
    |
    */
    'usage' => [
        'aws_key' => env('BEDROCK_USAGE_KEY', ''),
        'aws_secret' => env('BEDROCK_USAGE_SECRET', ''),
        'region' => env('BEDROCK_USAGE_REGION', env('BEDROCK_REGION', 'us-east-1')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | How long to cache various API responses.
    |
    */
    'cache' => [
        'pricing_ttl' => 86400,   // 24 hours
        'usage_ttl' => 900,       // 15 minutes
        'models_ttl' => 3600,     // 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Invocation Settings
    |--------------------------------------------------------------------------
    |
    | Default parameters for model invocations.
    |
    */
    'defaults' => [
        'max_tokens' => 4096,
        'temperature' => 0.7,
        'anthropic_version' => 'bedrock-2023-05-31',
        'model' => env('BEDROCK_DEFAULT_MODEL', ''),
        'image_model' => env('BEDROCK_DEFAULT_IMAGE_MODEL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Aliases
    |--------------------------------------------------------------------------
    |
    | Define short aliases for frequently used model IDs. Use the alias
    | anywhere a model ID is accepted and it will be resolved automatically.
    |
    | Example: Bedrock::invoke('claude', 'system', 'hello')
    |
    */
    'aliases' => [
        // 'claude' => 'anthropic.claude-sonnet-4-20250514-v1:0',
        // 'haiku'  => 'anthropic.claude-3-5-haiku-20241022-v1:0',
        // 'nova'   => 'amazon.nova-pro-v1:0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Invocation Logging
    |--------------------------------------------------------------------------
    |
    | Log every Bedrock invocation for auditing and cost tracking.
    | Set the channel to any configured Laravel log channel.
    |
    */
    'logging' => [
        'enabled' => env('BEDROCK_LOGGING_ENABLED', false),
        'channel' => env('BEDROCK_LOG_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check
    |--------------------------------------------------------------------------
    |
    | Register a /health/bedrock route for monitoring dashboards.
    | Protected by the specified middleware.
    |
    */
    'health_check' => [
        'enabled' => env('BEDROCK_HEALTH_CHECK_ENABLED', false),
        'path' => '/health/bedrock',
        'middleware' => [],
    ],

];
