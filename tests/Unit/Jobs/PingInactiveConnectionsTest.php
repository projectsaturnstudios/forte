<?php

use JMac\Testing\Double;
use ProjectSaturnStudios\Forte\Jobs\PingInactiveConnections;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\ChannelBroker;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Contracts\ChannelManager;

beforeEach(function () {
    $this->channelManager = Double::for(ChannelManager::class);
    $this->channelManager->expects('for')->returns($this->channelManager);
    $this->app->singleton(ChannelManager::class, fn () => $this->channelManager);
});

it('pings inactive connections', function () {
    $connections = factory(5);
    $channel = ChannelBroker::create('test-channel');

    $this->channelManager->expects('connections')->returns($connections);

    $connections = collect($connections)->each(function ($connection) use ($channel) {
        $channel->subscribe($connection->connection());
        $connection->setLastSeenAt(time() - 60 * 10);
    });

    (new PingInactiveConnections)->handle($this->channelManager);

    $connections->each(function ($connection) {
        $connection->assertReceived([
            'event' => 'pusher:ping',
        ]);
        $connection->assertHasBeenPinged();
    });
});
