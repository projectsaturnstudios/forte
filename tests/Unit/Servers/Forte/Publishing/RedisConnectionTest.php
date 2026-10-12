<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\SelectDriver;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RedisConnection;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RespError;

function fakeRedis(): array
{
    $loop = new Loop(new SelectDriver);
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    stream_set_blocking($server, false);
    stream_set_blocking($client, false);

    return [$loop, new RedisConnection($client, $loop), $server];
}

function spinLoop(Loop $loop, int $n = 5): void
{
    for ($i = 0; $i < $n; $i++) {
        $loop->tick(0.0);
    }
}

it('publishes and resolves with the subscriber count', function () {
    [$loop, $redis, $server] = fakeRedis();
    $result = null;
    $redis->publish('forte', '{"x":1}')->then(function ($count) use (&$result) { $result = $count; });
    spinLoop($loop);

    expect(fread($server, 200))->toBe("*3\r\n$7\r\nPUBLISH\r\n$5\r\nforte\r\n$7\r\n{\"x\":1}\r\n");
    fwrite($server, ":3\r\n");
    spinLoop($loop);

    expect($result)->toBe(3);
});

it('rejects a command answered with an error and keeps serving later commands', function () {
    [$loop, $redis, $server] = fakeRedis();
    $error = null;
    $ok = null;
    $redis->publish('forte', 'a')->then(null, function ($e) use (&$error) { $error = $e; });
    $redis->publish('forte', 'b')->then(function ($count) use (&$ok) { $ok = $count; });
    spinLoop($loop);
    fread($server, 1000);
    fwrite($server, "-NOAUTH Authentication required.\r\n:1\r\n");
    spinLoop($loop);

    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and($error->getMessage())->toBe('NOAUTH Authentication required.')
        ->and($ok)->toBe(1);
});

it('subscribes and emits message events for pushes', function () {
    [$loop, $redis, $server] = fakeRedis();
    $subscribed = false;
    $messages = [];
    $redis->on('message', function (string $channel, string $payload) use (&$messages) { $messages[] = [$channel, $payload]; });
    $redis->subscribe('forte')->then(function () use (&$subscribed) { $subscribed = true; });
    spinLoop($loop);

    expect(fread($server, 200))->toBe("*2\r\n$9\r\nSUBSCRIBE\r\n$5\r\nforte\r\n");
    fwrite($server, "*3\r\n$9\r\nsubscribe\r\n$5\r\nforte\r\n:1\r\n*3\r\n$7\r\nmessage\r\n$5\r\nforte\r\n$2\r\nhi\r\n");
    spinLoop($loop);

    expect($subscribed)->toBeTrue()->and($messages)->toBe([['forte', 'hi']]);
});

it('emits close when the server goes away', function () {
    [$loop, $redis, $server] = fakeRedis();
    $closed = 0;
    $redis->on('close', function () use (&$closed) { $closed++; });

    fclose($server);
    spinLoop($loop);
    spinLoop($loop);

    expect($closed)->toBe(1);
});

it('rejects pending commands on close', function () {
    [$loop, $redis, $server] = fakeRedis();
    $rejected = false;
    $redis->publish('forte', 'a')->then(null, function () use (&$rejected) { $rejected = true; });
    spinLoop($loop);

    $redis->close();

    expect($rejected)->toBeTrue();
});
