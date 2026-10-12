<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RedisClientFactory;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RedisConnection;

/** A fake Redis: accepts one client, records what it sends, answers each command line with $reply. */
function fakeRedisServer(Loop $loop, string $reply = "+OK\r\n"): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    stream_set_blocking($listener, false);
    $state = (object) ['received' => '', 'client' => null];

    $loop->onReadable($listener, function () use ($listener, $loop, $state, $reply) {
        $state->client = stream_socket_accept($listener, 0);
        stream_set_blocking($state->client, false);
        $loop->onReadable($state->client, function () use ($state, $reply) {
            $data = (string) fread($state->client, 8192);
            $state->received .= $data;
            fwrite($state->client, str_repeat($reply, substr_count($data, "*")));
        });
    });

    return [stream_socket_get_name($listener, false), $state];
}

function runUntil(Loop $loop, callable $done, float $seconds = 2.0): void
{
    $deadline = microtime(true) + $seconds;
    while (! $done() && microtime(true) < $deadline) {
        $loop->tick(0.01);
    }
}

it('connects and resolves with a connection', function () {
    $loop = Loop::create('select');
    [$address] = fakeRedisServer($loop);
    $connection = null;

    (new RedisClientFactory)->make($loop, "redis://{$address}")->then(function ($c) use (&$connection) { $connection = $c; });
    runUntil($loop, function () use (&$connection) { return $connection !== null; });

    expect($connection)->toBeInstanceOf(RedisConnection::class);
});

it('authenticates with username and password and selects the database before resolving', function () {
    $loop = Loop::create('select');
    [$address, $state] = fakeRedisServer($loop);
    $connection = null;

    (new RedisClientFactory)->make($loop, "redis://{$address}?username=forte&password=s3cret&db=2")->then(function ($c) use (&$connection) { $connection = $c; });
    runUntil($loop, function () use (&$connection) { return $connection !== null; });

    expect($connection)->toBeInstanceOf(RedisConnection::class)
        ->and($state->received)->toBe("*3\r\n$4\r\nAUTH\r\n$5\r\nforte\r\n$6\r\ns3cret\r\n*2\r\n$6\r\nSELECT\r\n$1\r\n2\r\n");
});

it('rejects when authentication fails', function () {
    $loop = Loop::create('select');
    [$address] = fakeRedisServer($loop, "-WRONGPASS invalid username-password pair\r\n");
    $error = null;

    (new RedisClientFactory)->make($loop, "redis://{$address}?password=nope")->then(null, function ($e) use (&$error) { $error = $e; });
    runUntil($loop, function () use (&$error) { return $error !== null; });

    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and($error->getMessage())->toContain('WRONGPASS');
});

it('rejects when nothing is listening', function () {
    $loop = Loop::create('select');
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    $error = null;

    (new RedisClientFactory)->make($loop, "redis://{$address}")->then(null, function ($e) use (&$error) { $error = $e; });
    runUntil($loop, function () use (&$error) { return $error !== null; });

    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and($error->getMessage())->toContain('Could not connect to Redis');
});
