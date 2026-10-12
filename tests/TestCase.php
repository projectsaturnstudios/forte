<?php

namespace ProjectSaturnStudios\Forte\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use ProjectSaturnStudios\Forte\ApplicationManagerServiceProvider;
use ProjectSaturnStudios\Forte\Contracts\Logger;
use ProjectSaturnStudios\Forte\Loggers\NullLogger;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Contracts\ChannelConnectionManager;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Contracts\ChannelManager;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Managers\ArrayChannelManager;
use ProjectSaturnStudios\Forte\ForteServiceProvider;
use Orchestra\Testbench\TestCase as TestbenchTestCase;

class TestCase extends TestbenchTestCase
{
    use VerifiesDoubles;

    /**
     * Setup the test environment.
     */
    protected function defineEnvironment($app): void
    {
        $app->instance(Logger::class, new NullLogger);

        $app->singleton(
            ChannelManager::class,
            fn () => new ArrayChannelManager
        );

        $app->bind(
            ChannelConnectionManager::class,
            fn () => new ArrayChannelConnectionManager
        );
    }

    /**
     * Get package providers.
     *
     * @param  Application  $app
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app)
    {
        return [
            ForteServiceProvider::class,
            ApplicationManagerServiceProvider::class,
        ];
    }
}
