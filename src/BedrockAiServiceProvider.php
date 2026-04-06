<?php

namespace Ubxty\BedrockAi;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BedrockAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bedrock.php', 'bedrock');

        $this->app->singleton(BedrockManager::class, function ($app) {
            return new BedrockManager($app['config']->get('bedrock', []));
        });

        $this->app->alias(BedrockManager::class, 'bedrock');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/bedrock.php' => config_path('bedrock.php'),
            ], 'bedrock-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'bedrock-migrations');

            $this->commands([
                Commands\ChatCommand::class,
                Commands\ConfigureCommand::class,
                Commands\ModelsCommand::class,
                Commands\TestCommand::class,
                Commands\UsageCommand::class,
                Commands\PricingCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->registerHealthCheckRoute();
    }

    protected function registerHealthCheckRoute(): void
    {
        $config = $this->app['config']->get('bedrock.health_check', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        $path = $config['path'] ?? '/health/bedrock';
        $middleware = $config['middleware'] ?? [];

        Route::get($path, Http\HealthCheckController::class)
            ->middleware($middleware)
            ->name('bedrock.health');
    }
}
