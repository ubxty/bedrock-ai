<?php

namespace Ubxty\BedrockAi\Commands;

use Illuminate\Console\Command;
use Ubxty\BedrockAi\BedrockManager;

class ConfigureCommand extends Command
{
    use WritesEnvFile;
    protected $signature = 'bedrock:configure
                            {--test : Test the connection after configuring}
                            {--show : Show current configuration (masked secrets)}';

    protected $description = 'Interactive wizard to configure AWS Bedrock credentials';

    public function handle(BedrockManager $manager): int
    {
        if ($this->option('show')) {
            return $this->showConfig($manager);
        }

        $this->info('');
        $this->info('  ╔═══════════════════════════════════════════╗');
        $this->info('  ║   AWS Bedrock Configuration Wizard        ║');
        $this->info('  ╚═══════════════════════════════════════════╝');
        $this->info('');

        // Step 1: Auth Mode
        $this->info('  Step 1: Authentication Mode');
        $this->line('  ─────────────────────────────────────────────');
        $this->line('  Choose how to authenticate with AWS Bedrock:');
        $this->line('  • <fg=yellow>iam</>     - AWS IAM Access Key + Secret (standard)');
        $this->line('  • <fg=yellow>bearer</>  - Bearer Token (e.g., ABSK tokens)');
        $this->newLine();

        $authMode = $this->choice('Authentication mode', ['iam', 'bearer'], 0);

        $awsKey = '';
        $awsSecret = '';
        $bearerToken = '';

        if ($authMode === 'bearer') {
            $bearerToken = $this->secret('Bearer Token (hidden)');
        } else {
            $awsKey = $this->ask('AWS Access Key ID');
            $awsSecret = $this->secret('AWS Secret Access Key (hidden)');
        }

        $region = $this->ask('AWS Region', 'us-east-1');
        $label = $this->ask('Key Label (for identification)', 'Primary');

        // Detect mode
        $this->newLine();
        $this->info('  Mode: ' . ($authMode === 'bearer' ? 'HTTP Bearer Token' : 'AWS SDK (IAM)'));

        // Step 2: Pricing API (optional)
        $this->newLine();
        $this->info('  Step 2: Pricing API (Optional)');
        $this->line('  ─────────────────────────────────────────────');
        $this->line('  Separate credentials for fetching real-time pricing.');
        $this->line('  Skip to use the same credentials as above.');
        $this->newLine();

        $pricingKey = '';
        $pricingSecret = '';
        if ($this->confirm('Configure separate Pricing API credentials?', false)) {
            $pricingKey = $this->ask('Pricing API Key');
            $pricingSecret = $this->secret('Pricing API Secret (hidden)');
        }

        // Step 3: Show summary
        $this->newLine();
        $this->info('  Configuration Summary');
        $this->line('  ─────────────────────────────────────────────');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Label', $label],
                ['Auth Mode', $authMode],
                ['AWS Key', $authMode === 'iam' ? $this->maskSecret($awsKey) : 'N/A'],
                ['Bearer Token', $authMode === 'bearer' ? $this->maskSecret($bearerToken) : 'N/A'],
                ['Region', $region],
                ['Pricing API', $pricingKey ? $this->maskSecret($pricingKey) : 'Same as above'],
            ]
        );

        // Step 4: Generate .env entries
        $this->newLine();
        $this->info('  Add these to your .env file:');
        $this->line('  ─────────────────────────────────────────────');
        $this->newLine();
        $this->line("  BEDROCK_KEY_LABEL={$label}");
        $this->line("  BEDROCK_AUTH_MODE={$authMode}");

        if ($authMode === 'bearer') {
            $this->line("  BEDROCK_BEARER_TOKEN={$bearerToken}");
        } else {
            $this->line("  BEDROCK_AWS_KEY={$awsKey}");
            $this->line("  BEDROCK_AWS_SECRET={$awsSecret}");
        }

        $this->line("  BEDROCK_REGION={$region}");

        if ($pricingKey) {
            $this->line("  BEDROCK_PRICING_KEY={$pricingKey}");
            $this->line("  BEDROCK_PRICING_SECRET={$pricingSecret}");
        }

        // Step 5: Write to .env
        $this->newLine();
        if ($this->confirm('Write these to your .env file automatically?', true)) {
            $envValues = [
                'BEDROCK_KEY_LABEL' => $label,
                'BEDROCK_AUTH_MODE' => $authMode,
                'BEDROCK_REGION' => $region,
            ];

            if ($authMode === 'bearer') {
                $envValues['BEDROCK_BEARER_TOKEN'] = $bearerToken;
            } else {
                $envValues['BEDROCK_AWS_KEY'] = $awsKey;
                $envValues['BEDROCK_AWS_SECRET'] = $awsSecret;
            }

            if ($pricingKey) {
                $envValues['BEDROCK_PRICING_KEY'] = $pricingKey;
                $envValues['BEDROCK_PRICING_SECRET'] = $pricingSecret;
            }

            $this->writeEnv($envValues);

            $this->info('  ✓ .env file updated.');

            // Clear config cache so the next artisan command picks up the new values.
            try {
                $this->call('config:clear', [], new \Symfony\Component\Console\Output\NullOutput());
            } catch (\Throwable) {
                // Silently ignore if config:clear is unavailable.
            }
            $this->line('  ✓ Config cache cleared.');
        }

        // Step 6: Test connection
        if ($this->option('test') || $this->confirm('Test connection now?', true)) {
            $this->newLine();
            $this->info('  Testing connection...');

            // Build a temporary manager with the new credentials
            $testConfig = [
                'default' => 'default',
                'connections' => [
                    'default' => [
                        'keys' => [[
                            'label' => $label,
                            'auth_mode' => $authMode,
                            'aws_key' => $awsKey,
                            'aws_secret' => $awsSecret,
                            'bearer_token' => $bearerToken,
                            'region' => $region,
                        ]],
                    ],
                ],
                'retry' => config('bedrock.retry', []),
                'defaults' => config('bedrock.defaults', []),
            ];

            $testManager = new BedrockManager($testConfig);
            $result = $testManager->testConnection();

            if ($result['success']) {
                $this->info('  ✓ ' . $result['message']);
                $this->info("  Response time: {$result['response_time']}ms");
            } else {
                $this->error('  ✗ Connection failed: ' . $result['message']);

                return 1;
            }
        }

        $this->newLine();
        $this->info('  ✓ Configuration complete!');
        $this->newLine();

        return 0;
    }

    protected function showConfig(BedrockManager $manager): int
    {
        $config = $manager->getConfig();

        $this->info('');
        $this->info('  Current Bedrock Configuration');
        $this->line('  ─────────────────────────────────────────────');
        $this->newLine();

        $this->info("  Default Connection: " . ($config['default'] ?? 'default'));
        $this->info("  Configured: " . ($manager->isConfigured() ? 'Yes' : 'No'));
        $this->newLine();

        foreach ($config['connections'] ?? [] as $name => $connection) {
            $this->info("  Connection: {$name}");
            foreach ($connection['keys'] ?? [] as $i => $key) {
                $this->table(
                    ['Setting', 'Value'],
                    [
                        ['Label', $key['label'] ?? 'Key ' . ($i + 1)],
                        ['Auth Mode', $key['auth_mode'] ?? 'iam'],
                        ['AWS Key', ($key['auth_mode'] ?? 'iam') === 'iam' ? $this->maskSecret($key['aws_key'] ?? '') : 'N/A'],
                        ['Bearer Token', ($key['auth_mode'] ?? 'iam') === 'bearer' ? $this->maskSecret($key['bearer_token'] ?? '') : 'N/A'],
                        ['Region', $key['region'] ?? 'us-east-1'],
                    ]
                );
            }
        }

        $this->info('  Retry: max ' . ($config['retry']['max_retries'] ?? 3) . ', base delay ' . ($config['retry']['base_delay'] ?? 2) . 's');

        $limits = $config['limits'] ?? [];
        $this->info('  Daily limit: ' . ($limits['daily'] ? '$' . $limits['daily'] : 'None'));
        $this->info('  Monthly limit: ' . ($limits['monthly'] ? '$' . $limits['monthly'] : 'None'));
        $this->newLine();

        return 0;
    }

    protected function maskSecret(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
    }
}
