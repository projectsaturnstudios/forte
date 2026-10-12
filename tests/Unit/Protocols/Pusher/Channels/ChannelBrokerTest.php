<?php

use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\CacheChannel;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\Channel;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\ChannelBroker;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\PresenceCacheChannel;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\PresenceChannel;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\PrivateCacheChannel;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\PrivateChannel;

it('can return a channel instance', function () {
    expect(ChannelBroker::create('foo'))
        ->toBeInstanceOf(Channel::class);
});

it('can return a private channel instance', function () {
    expect(ChannelBroker::create('private-foo'))
        ->toBeInstanceOf(PrivateChannel::class);
});

it('can return a presence channel instance', function () {
    expect(ChannelBroker::create('presence-foo'))
        ->toBeInstanceOf(PresenceChannel::class);
});

it('can return a cache channel instance', function () {
    expect(ChannelBroker::create('cache-foo'))
        ->toBeInstanceOf(CacheChannel::class);
});

it('can return a private cache channel instance', function () {
    expect(ChannelBroker::create('private-cache-foo'))
        ->toBeInstanceOf(PrivateCacheChannel::class);
});

it('can return a presence cache channel instance', function () {
    expect(ChannelBroker::create('presence-cache-foo'))
        ->toBeInstanceOf(PresenceCacheChannel::class);
});
