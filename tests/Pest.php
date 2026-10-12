<?php

use Illuminate\Support\Collection;
use ProjectSaturnStudios\Forte\Application;
use ProjectSaturnStudios\Forte\Connection;
use ProjectSaturnStudios\Forte\Contracts\ApplicationProvider;
use ProjectSaturnStudios\Forte\Managers\Connections;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\ChannelConnection;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Contracts\ChannelManager;
use ProjectSaturnStudios\Forte\Tests\FakeConnection;
use ProjectSaturnStudios\Forte\Tests\SerializableConnection;
use ProjectSaturnStudios\Forte\Tests\TestCase;
use Ramsey\Uuid\Uuid;

uses(TestCase::class)->in(__DIR__.'/Unit');

/**
 * Create a defined number of connections.
 *
 * @return array<int, Connection|string>
 */
function factory(int $count = 1, array $data = [], bool $serializable = false): array
{
    return Collection::make(range(1, $count))->map(function () use ($data, $serializable) {
        return new ChannelConnection(
            $serializable
                ? new SerializableConnection(Uuid::uuid4())
                : new FakeConnection(Uuid::uuid4()),
            $data
        );
    })->all();
}

/**
 * Generate a valid Pusher authentication signature.
 */
function validAuth(string $connectionId, string $channel, ?string $data = null): string
{
    $signature = "{$connectionId}:{$channel}";

    if ($data) {
        $signature .= ":{$data}";
    }

    return 'app-key:'.hash_hmac('sha256', $signature, 'reverb-secret');
}

/**
 * Return the channel manager.
 */
function channels(?Application $app = null): ChannelManager
{
    return app(ChannelManager::class)
        ->for($app ?: app(ApplicationProvider::class)->all()->first());
}
