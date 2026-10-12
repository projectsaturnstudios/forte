<?php

use JMac\Testing\Double;
use ProjectSaturnStudios\Forte\Protocols\Pusher\MetricsHandler;
use ProjectSaturnStudios\Forte\Servers\Forte\Contracts\PubSubProvider;
use ProjectSaturnStudios\Forte\Servers\Forte\Factory;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;

it('resolves the metrics handler without a server having been built', function () {
    $this->app->instance(PubSubProvider::class, Double::for(PubSubProvider::class));

    expect(app(MetricsHandler::class))->toBeInstanceOf(MetricsHandler::class)
        ->and(app(LoopInterface::class))->toBe(app(LoopInterface::class));
});

it('binds the server loop so the metrics handler shares it', function () {
    $loop = Loop::create('select');
    $server = Factory::make('127.0.0.1', '0', loop: $loop);

    expect(app(LoopInterface::class))->toBe($loop);

    $server->stop();
});
