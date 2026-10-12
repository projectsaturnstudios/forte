<?php

namespace Bench;

use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;

/** One Pusher client: handshake, subscribe to the bench channel, answer pings, time each bench event. */
final class ClientConnection
{
    private bool $handshaking = true;

    private bool $subscribed = false;

    private bool $settled = false;

    private bool $closed = false;

    private string $in = '';

    private string $out = '';

    private ?MessageBuffer $frames = null;

    /** @param resource $stream a socket from an async connect */
    public function __construct(private $stream, private LoopInterface $loop, private Client $client, private int $port, private string $appKey)
    {
        $loop->onWritable($stream, $this->connected(...));
    }

    private function connected(): void
    {
        $this->loop->cancelWritable($this->stream);

        if (@stream_socket_get_name($this->stream, true) === false) {
            $this->fail();

            return;
        }

        $this->loop->onReadable($this->stream, $this->readable(...));
        $key = base64_encode(random_bytes(16));
        $this->write("GET /app/{$this->appKey}?protocol=7&client=bench&version=1.0 HTTP/1.1\r\n"
            ."Host: 127.0.0.1:{$this->port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    }

    private function readable(): void
    {
        $data = @fread($this->stream, 65536);

        if ($data === false || ($data === '' && feof($this->stream))) {
            $this->subscribed ? $this->drop() : $this->fail();

            return;
        }

        if (! $this->handshaking) {
            $this->frames->onData($data);

            return;
        }

        $this->in .= $data;

        if (($end = strpos($this->in, "\r\n\r\n")) === false) {
            return;
        }

        if (! str_starts_with($this->in, 'HTTP/1.1 101')) {
            $this->fail();

            return;
        }

        $rest = substr($this->in, $end + 4);
        $this->in = '';
        $this->handshaking = false;
        $this->frames = new MessageBuffer(
            new CloseFrameChecker,
            $this->message(...),
            $this->control(...),
            expectMask: false,
            maxMessagePayloadSize: 0,
            maxFramePayloadSize: 0,
            sender: fn (string $bytes) => $this->write($bytes),
        );

        if ($rest !== '') {
            $this->frames->onData($rest);
        }
    }

    private function message(MessageInterface $message): void
    {
        $now = microtime(true);
        $event = json_decode((string) $message, true);

        switch ($event['event'] ?? null) {
            case 'bench':
                $payload = json_decode($event['data'], true);
                $this->client->event((int) $payload['g'], ($now - (float) $payload['t']) * 1000, $now, (int) ($payload['n'] ?? -1));
                break;
            case 'pusher:connection_established':
                $this->send(['event' => 'pusher:subscribe', 'data' => ['channel' => 'bench']]);
                break;
            case 'pusher_internal:subscription_succeeded':
                $this->subscribed = true;
                $this->settled = true;
                $this->client->subscribed();
                break;
            case 'pusher:ping':
                $this->send(['event' => 'pusher:pong', 'data' => []]);
                $this->client->pinged();
                break;
            case 'pusher:error':
                $this->fail();
                break;
        }
    }

    private function control(FrameInterface $frame): void
    {
        match ($frame->getOpcode()) {
            Frame::OP_PING => $this->frame(new Frame($frame->getPayload(), true, Frame::OP_PONG)),
            Frame::OP_CLOSE => $this->subscribed ? $this->drop() : $this->fail(),
            default => null,
        };
    }

    private function send(array $event): void
    {
        $this->frame(new Frame(json_encode($event), true, Frame::OP_TEXT));
    }

    private function frame(Frame $frame): void
    {
        $frame->maskPayload();
        $this->write($frame->getContents());
    }

    private function write(string $bytes): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->out === '') {
            $written = @fwrite($this->stream, $bytes);

            if ($written === false) {
                $this->subscribed ? $this->drop() : $this->fail();

                return;
            }

            $bytes = (string) substr($bytes, $written);
        }

        if ($bytes !== '') {
            $this->out .= $bytes;
            $this->loop->onWritable($this->stream, $this->flush(...));
        }
    }

    private function flush(): void
    {
        $written = @fwrite($this->stream, $this->out);

        if ($written === false) {
            $this->subscribed ? $this->drop() : $this->fail();

            return;
        }

        $this->out = (string) substr($this->out, $written);

        if ($this->out === '') {
            $this->loop->cancelWritable($this->stream);
        }
    }

    private function fail(): void
    {
        if (! $this->settled) {
            $this->settled = true;
            $this->client->failed();
        }

        $this->close();
    }

    private function drop(): void
    {
        if ($this->subscribed) {
            $this->subscribed = false;
            $this->client->dropped();
        }

        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->loop->cancelReadable($this->stream);
        $this->loop->cancelWritable($this->stream);
        @fclose($this->stream);
    }
}
