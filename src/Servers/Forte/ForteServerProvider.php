<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Foundation\Application;
use ProjectSaturnStudios\Forte\Contracts\ServerProvider;
use ProjectSaturnStudios\Forte\Servers\Forte\Console\Commands\RestartServer;
use ProjectSaturnStudios\Forte\Servers\Forte\Console\Commands\StartServer;
use ProjectSaturnStudios\Forte\Servers\Forte\Contracts\PubSubIncomingMessageHandler;
use ProjectSaturnStudios\Forte\Servers\Forte\Contracts\PubSubProvider;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RedisClientFactory;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RedisPubSubProvider;

class ForteServerProvider extends ServerProvider
{
    /**
     * Indicates whether the Reverb server should publish events.
     *
     * @var bool
     */
    protected $publishesEvents;

    /**
     * Create a new Reverb server provider instance.
     */
    public function __construct(protected Application $app, protected array $config)
    {
        $this->publishesEvents = (bool) $this->config['scaling']['enabled'] ?? false;
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PubSubProvider::class, fn ($app) => new RedisPubSubProvider(
            $app->make(RedisClientFactory::class),
            $app->make(PubSubIncomingMessageHandler::class),
            $this->config['scaling']['channel'] ?? 'reverb',
            $this->config['scaling']['server'] ?? []
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            Artisan::starting(function ($artisan) {
                $artisan->resolveCommands([
                    StartServer::class,
                    RestartServer::class,
                ]);
            });
        }
    }

    /**
     * Enable publishing of events.
     */
    public function withPublishing(): void
    {
        $this->publishesEvents = true;
    }

    /**
     * Determine whether the server should publish events.
     */
    public function shouldPublishEvents(): bool
    {
        return $this->publishesEvents;
    }
}
