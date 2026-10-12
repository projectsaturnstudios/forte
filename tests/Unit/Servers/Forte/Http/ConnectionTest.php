<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Http\Connection;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\SelectDriver;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;

function pairOnLoop(): array
{
    $loop = new Loop(new SelectDriver);
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    stream_set_blocking($server, false);
    stream_set_blocking($client, false);

    return [$loop, new Connection($server, $loop), $client];
}

function pump(Loop $loop, int $times = 5): void
{
    for ($i = 0; $i < $times; $i++) {
        $loop->tick(0.0);
    }
}

it('delivers incoming bytes to the data callback', function () {
    [$loop, $connection, $client] = pairOnLoop();
    $received = '';
    $connection->onData(function (string $data) use (&$received) { $received .= $data; });

    fwrite($client, 'hello');
    pump($loop);

    expect($received)->toBe('hello');
});

it('writes immediately when the socket accepts it', function () {
    [$loop, $connection, $client] = pairOnLoop();

    $connection->send('pong');
    pump($loop);

    expect(fread($client, 10))->toBe('pong');
});

it('buffers a write the socket cannot take and flushes it when writable', function () {
    [$loop, $connection, $client] = pairOnLoop();
    // Under the 1 MiB cap, over any socket buffer: most of it has to wait for writable.
    $payload = str_repeat('x', 900_000);

    $connection->send($payload);
    $total = '';
    for ($i = 0; $i < 200 && strlen($total) < strlen($payload); $i++) {
        $loop->tick(0.0);
        $total .= stream_get_contents($client);
    }

    expect(strlen($total))->toBe(strlen($payload));
});

it('closes once when the peer disconnects, and fires the close callback once', function () {
    [$loop, $connection, $client] = pairOnLoop();
    $closed = 0;
    $connection->onClose(function () use (&$closed) { $closed++; });

    fclose($client);
    pump($loop);
    pump($loop);

    expect($closed)->toBe(1);
    expect(is_resource($connection->stream()))->toBeFalse();
});

it('close flushes pending output before closing the stream', function () {
    [$loop, $connection, $client] = pairOnLoop();

    $connection->send('bye')->close();
    pump($loop);

    expect(fread($client, 10))->toBe('bye');
    expect(is_resource($connection->stream()))->toBeFalse();
});

it('drops a connection whose write buffer exceeds the cap', function () {
    [$loop, $connection, $client] = pairOnLoop();
    $closed = 0;
    $connection->onClose(function () use (&$closed) { $closed++; });

    // The kernel takes the first few KB of each write; two cap-sized sends overflow what is left.
    $connection->send(str_repeat('x', Connection::MAX_WRITE_BUFFER));
    $connection->send(str_repeat('x', Connection::MAX_WRITE_BUFFER));
    pump($loop);

    expect($closed)->toBe(1);
});

it('keeps the HTTP request buffer like Reverb', function () {
    [, $connection] = pairOnLoop();

    expect($connection->hasBuffer())->toBeFalse();
    $connection->appendToBuffer('GET / HTTP/1.1');
    expect($connection->bufferLength())->toBe(14)->and($connection->buffer())->toBe('GET / HTTP/1.1');
    $connection->clearBuffer();
    expect($connection->hasBuffer())->toBeFalse();
    expect($connection->isConnected())->toBeFalse();
    $connection->connect();
    expect($connection->isConnected())->toBeTrue();
});

it('completes a TLS handshake against a PHP TLS client', function () {
    $loop = new Loop(new SelectDriver);
    $cert = sys_get_temp_dir().'/forte-test-'.getmypid().'.pem';
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
    $x509 = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($x509, $certOut);
    openssl_pkey_export($key, $keyOut);
    file_put_contents($cert, $certOut.$keyOut);

    $context = stream_context_create(['ssl' => ['local_cert' => $cert, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    stream_set_blocking($listener, false);
    $address = stream_socket_get_name($listener, false);

    $received = '';
    $loop->onReadable($listener, function () use ($listener, $loop, &$received, &$connection) {
        $socket = stream_socket_accept($listener, 0);
        stream_set_blocking($socket, false);
        $connection = new Connection($socket, $loop, tls: true);
        $connection->onData(function (string $data) use (&$received, $connection) {
            $received .= $data;
            $connection->send('ok');
        });
    });

    $clientContext = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $client = stream_socket_client("tcp://{$address}", $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $clientContext);
    stream_set_blocking($client, false);

    $crypto = 0;
    for ($i = 0; $i < 500 && $crypto !== true; $i++) {
        $loop->tick(0.0);
        $crypto = stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        usleep(1000);
    }
    expect($crypto)->toBeTrue();

    fwrite($client, 'hi');
    $reply = '';
    for ($i = 0; $i < 500 && $reply === ''; $i++) {
        $loop->tick(0.0);
        $reply .= (string) fread($client, 10);
        usleep(1000);
    }

    expect($received)->toBe('hi')->and($reply)->toBe('ok')->and($connection->isSecure())->toBeTrue();
    unlink($cert);
});

it('accepts a larger write buffer when the cap is raised', function () {
    $loop = new Loop(new SelectDriver);
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    stream_set_blocking($server, false);
    stream_set_blocking($client, false);
    $connection = new Connection($server, $loop, maxWriteBuffer: 4 * 1_048_576);
    $closed = 0;
    $connection->onClose(function () use (&$closed) { $closed++; });

    $connection->send(str_repeat('x', 2_000_000));
    $total = 0;
    for ($i = 0; $i < 400 && $total < 2_000_000; $i++) {
        $loop->tick(0.0);
        $total += strlen((string) stream_get_contents($client));
    }

    expect($closed)->toBe(0)->and($total)->toBe(2_000_000);
});
