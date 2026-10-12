<?php

namespace ProjectSaturnStudios\Forte\Protocols\Pusher;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ProjectSaturnStudios\Forte\Application;
use ProjectSaturnStudios\Forte\Contracts\Connection;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Contracts\ChannelManager;
use ProjectSaturnStudios\Forte\ServerProviderManager;
use ProjectSaturnStudios\Forte\Servers\Forte\Contracts\PubSubProvider;

class EventDispatcher
{
    /**
     * Dispatch a message to a channel.
     */
    public static function dispatch(Application $app, array $payload, ?Connection $connection = null, ?string $socketId = null): void
    {
        $server = app(ServerProviderManager::class);

        if ($server->shouldNotPublishEvents()) {
            static::dispatchSynchronously($app, $payload, $connection);

            return;
        }

        $data = [
            'type' => 'message',
            'application' => serialize($app),
            'payload' => $payload,
        ];

        $socketId ??= $connection?->id();

        if ($socketId !== null) {
            $data['socket_id'] = $socketId;
        }

        app(PubSubProvider::class)->publish($data);
    }

    /**
     * Notify all connections subscribed to the given channel.
     */
    public static function dispatchSynchronously(Application $app, array $payload, ?Connection $connection = null): void
    {
        $channels = Arr::wrap($payload['channels'] ?? $payload['channel'] ?? []);

        foreach ($channels as $channel) {
            unset($payload['channels']);

            if (! $channel = app(ChannelManager::class)->for($app)->find($channel)) {
                continue;
            }

            $payload['channel'] = $channel->name();

            Str::startsWith($payload['event'] ?? '', 'pusher_internal:')
                ? $channel->broadcastInternally($payload, $connection)
                : $channel->broadcast($payload, $connection);
        }
    }
}
