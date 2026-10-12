<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Publishing;

use ProjectSaturnStudios\Forte\Promise\Deferred;
use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use RuntimeException;

class RedisClientFactory
{
    /**
     * Connect to redis://host:port?username=&password=&db= (or rediss:// for TLS) and resolve
     * with a RedisConnection once connected, authenticated and on the right database.
     *
     * @return Promise<RedisConnection>
     */
    public function make(LoopInterface $loop, string $redisUrl): Promise
    {
        $parts = parse_url($redisUrl);
        if ($parts === false || ! isset($parts['host'])) {
            return Promise::reject(new RuntimeException("Invalid Redis URL [{$redisUrl}]."));
        }

        parse_str($parts['query'] ?? '', $query);
        $tls = ($parts['scheme'] ?? 'redis') === 'rediss';
        $port = $parts['port'] ?? 6379;
        $address = "tcp://{$parts['host']}:{$port}";

        $socket = @stream_socket_client($address, $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($socket === false) {
            return Promise::reject(new RuntimeException("Could not connect to Redis at {$address}: {$errstr}"));
        }
        stream_set_blocking($socket, false);

        $deferred = new Deferred;

        $loop->onWritable($socket, function () use ($loop, $socket, $deferred, $tls, $query, $address) {
            $loop->cancelWritable($socket);

            // A failed async connect reports writable too; the peer name is the tell.
            if (@stream_socket_get_name($socket, true) === false) {
                @fclose($socket);
                $deferred->reject(new RuntimeException("Could not connect to Redis at {$address}."));

                return;
            }

            $ready = function () use ($loop, $socket, $deferred, $query) {
                $connection = new RedisConnection($socket, $loop);
                $steps = Promise::resolve(null);

                if (isset($query['password'])) {
                    $steps = $steps->then(fn () => $connection->auth($query['username'] ?? null, $query['password']));
                }
                if (isset($query['db']) && (int) $query['db'] !== 0) {
                    $steps = $steps->then(fn () => $connection->select((int) $query['db']));
                }

                $steps->then(fn () => $deferred->resolve($connection), function ($e) use ($connection, $deferred) {
                    $connection->close();
                    $deferred->reject($e);
                });
            };

            if (! $tls) {
                $ready();

                return;
            }

            $handshake = function () use ($loop, $socket, $deferred, $ready, &$handshake) {
                $result = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($result === 0) {
                    return; // not yet; stay armed on both
                }
                $loop->cancelReadable($socket);
                $loop->cancelWritable($socket);
                if ($result === false) {
                    @fclose($socket);
                    $deferred->reject(new RuntimeException('TLS handshake with Redis failed.'));

                    return;
                }
                $ready();
            };
            $loop->onReadable($socket, $handshake);
            $loop->onWritable($socket, $handshake);
            $handshake();
        });

        return $deferred->promise();
    }
}
