<?php

use Illuminate\Support\Str;
use ProjectSaturnStudios\Forte\Promise\Deferred;
use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use ProjectSaturnStudios\Forte\Tests\TestConnection;
use ProjectSaturnStudios\Forte\Tests\TimeoutException;
use ProjectSaturnStudios\Forte\Tests\WebSocketClient;

/**
 * Run the server's loop until the promise settles; return its value or throw its reason.
 */
function await(Promise $promise, float $timeout = 10.0): mixed
{
    $settled = false;
    $value = null;
    $reason = null;

    $promise->then(function (mixed $result) use (&$settled, &$value) {
        $settled = true;
        $value = $result;
    }, function (Throwable $error) use (&$settled, &$reason) {
        $settled = true;
        $reason = $error;
    });

    $deadline = microtime(true) + $timeout;

    while (! $settled) {
        if (($left = $deadline - microtime(true)) <= 0) {
            throw new TimeoutException("Nothing settled within {$timeout} seconds.");
        }

        app(LoopInterface::class)->tick(min($left, 0.05));
    }

    if ($reason !== null) {
        throw $reason;
    }

    return $value;
}

/**
 * Run the server's loop for the given number of seconds.
 */
function runLoopFor(float $seconds): void
{
    $deadline = microtime(true) + $seconds;

    while (($left = $deadline - microtime(true)) > 0) {
        app(LoopInterface::class)->tick(min($left, 0.05));
    }
}

/**
 * Open a WebSocket connection on the server's loop without waiting for any message.
 *
 * @return Promise<WebSocketClient>
 */
function wsConnect(string $url, array $headers = []): Promise
{
    return WebSocketClient::connect(app(LoopInterface::class), $url, $headers);
}

/**
 * Connect to the WebSocket server.
 */
function connect(string $host = '0.0.0.0', string $port = '8080', string $key = 'reverb-key', array $headers = []): TestConnection
{
    $promise = new Deferred;

    $connection = await(wsConnect("ws://{$host}:{$port}/app/{$key}", $headers));

    $connection = new TestConnection($connection);

    $connection->on('message', function ($message) use ($promise) {
        $promise->resolve((string) $message);
    });

    $message = await($promise->promise());

    expect($message)->toContain('connection_established');

    $connection->socketId = json_decode(json_decode($message)->data)->socket_id;

    return $connection;
}

/**
 * Send a message to the connected client.
 */
function send(array $message, ?TestConnection $connection = null)
{
    $connection = $connection ?: connect();

    $connection->send(json_encode($message));

    return $connection->await();
}

/**
 * Subscribe to a channel.
 */
function subscribe(string $channel, ?array $data = [], ?string $auth = null, ?TestConnection $connection = null): string
{
    $data = ! empty($data) ? json_encode($data) : null;

    if (! $auth && Str::startsWith($channel, ['private-', 'presence-'])) {
        $connection = $connection ?: connect();
        $auth = validAuth($connection->socketId(), $channel, $data);
    }

    return send([
        'event' => 'pusher:subscribe',
        'data' => array_filter([
            'channel' => $channel,
            'channel_data' => $data,
            'auth' => $auth,
        ]),
    ], $connection);
}

/**
 * Unsubscribe to a channel.
 */
function unsubscribe(string $channel, ?TestConnection $connection = null): ?string
{
    return send([
        'event' => 'pusher:unsubscribe',
        'data' => ['channel' => $channel],
    ], $connection);
}

/**
 * Disconnect the connected client.
 */
function disconnect(TestConnection $connection): string
{
    $promise = new Deferred;

    $connection->on('close', function () use ($promise) {
        $promise->resolve('Connection Closed.');
    });

    $connection->close();

    return await($promise->promise());
}
