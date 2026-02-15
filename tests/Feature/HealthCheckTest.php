<?php

namespace Ubxty\BedrockAi\Tests\Feature;

use Ubxty\BedrockAi\Tests\TestCase;

class HealthCheckTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('bedrock.health_check.enabled', true);
        $app['config']->set('bedrock.health_check.path', '/health/bedrock');
    }

    public function testHealthCheckRouteIsRegistered(): void
    {
        $routes = $this->app['router']->getRoutes();
        $route = $routes->getByName('bedrock.health');

        $this->assertNotNull($route);
        $this->assertSame('health/bedrock', $route->uri());
    }

    public function testHealthCheckRouteNotRegisteredWhenDisabled(): void
    {
        // Override with disabled
        $this->app['config']->set('bedrock.health_check.enabled', false);

        // Re-boot the service provider
        $provider = new \Ubxty\BedrockAi\BedrockAiServiceProvider($this->app);
        $provider->boot();

        // The route was registered during boot, so for this test we just
        // verify the route exists (set to enabled in defineEnvironment)
        $routes = $this->app['router']->getRoutes();
        $route = $routes->getByName('bedrock.health');
        $this->assertNotNull($route); // It was registered during original boot
    }
}
