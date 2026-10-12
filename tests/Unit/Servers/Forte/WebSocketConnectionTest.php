<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Connection as WebSocketConnection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Connection as HttpConnection;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\SelectDriver;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use Ratchet\RFC6455\Messaging\Frame;

function wsPair(): array
{
    $loop = new Loop(new SelectDriver);
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    stream_set_blocking($server, false);
    stream_set_blocking($client, false);
    $http = new HttpConnection($server, $loop);
    $ws = new WebSocketConnection($http);

    return [$loop, $ws, $client];
}

function maskedTextFrame(string $payload): string
{
    $frame = new Frame($payload, true, Frame::OP_TEXT);
    $frame->maskPayload();

    return $frame->getContents();
}

function spin(Loop $loop, int $n = 5): void
{
    for ($i = 0; $i < $n; $i++) {
        $loop->tick(0.0);
    }
}

it('delivers a complete masked text frame to the message handler', function () {
    [$loop, $ws, $client] = wsPair();
    $got = null;
    $ws->onMessage(function ($message) use (&$got) { $got = (string) $message; });
    $ws->openBuffer();

    fwrite($client, maskedTextFrame('{"event":"pusher:ping"}'));
    spin($loop);

    expect($got)->toBe('{"event":"pusher:ping"}');
});

it('reassembles a frame split across two reads', function () {
    [$loop, $ws, $client] = wsPair();
    $got = null;
    $ws->onMessage(function ($message) use (&$got) { $got = (string) $message; });
    $ws->openBuffer();
    $bytes = maskedTextFrame(str_repeat('a', 300));

    fwrite($client, substr($bytes, 0, 100));
    spin($loop);
    expect($got)->toBeNull();
    fwrite($client, substr($bytes, 100));
    spin($loop);

    expect($got)->toBe(str_repeat('a', 300));
});

it('answers a ping with a pong carrying the same payload', function () {
    [$loop, $ws, $client] = wsPair();
    $ws->openBuffer();
    $ping = new Frame('p1', true, Frame::OP_PING);
    $ping->maskPayload();

    fwrite($client, $ping->getContents());
    spin($loop);
    $pong = new Frame;
    $pong->addBuffer((string) fread($client, 100));

    expect($pong->getOpcode())->toBe(Frame::OP_PONG)->and($pong->getPayload())->toBe('p1');
});

it('sends a string as an unmasked text frame', function () {
    [$loop, $ws, $client] = wsPair();

    $ws->send('hello');
    spin($loop);
    $frame = new Frame;
    $frame->addBuffer((string) fread($client, 100));

    expect($frame->getOpcode())->toBe(Frame::OP_TEXT)->and($frame->getPayload())->toBe('hello');
});

it('closes with a close frame and fires the close handler', function () {
    [$loop, $ws, $client] = wsPair();
    $closed = 0;
    $ws->onClose(function () use (&$closed) { $closed++; });
    $ws->openBuffer();

    $ws->close(new Frame(pack('n', Frame::CLOSE_NORMAL), true, Frame::OP_CLOSE));
    spin($loop);
    $frame = new Frame;
    $frame->addBuffer((string) fread($client, 100));

    expect($frame->getOpcode())->toBe(Frame::OP_CLOSE)->and($closed)->toBe(1);
});

it('fires the close handler once when the peer disconnects', function () {
    [$loop, $ws, $client] = wsPair();
    $closed = 0;
    $ws->onClose(function () use (&$closed) { $closed++; });
    $ws->openBuffer();

    fclose($client);
    spin($loop);
    spin($loop);

    expect($closed)->toBe(1);
});

it('feeds bytes that arrived with the handshake into the frame buffer', function () {
    [$loop, $ws, $client] = wsPair();
    $got = null;
    $ws->onMessage(function ($message) use (&$got) { $got = (string) $message; });
    $ws->connection->appendToBuffer("GET /app/k HTTP/1.1\r\nUpgrade: websocket\r\n\r\n".maskedTextFrame('early'));

    $ws->openBuffer();
    spin($loop);

    expect($got)->toBe('early');
});
