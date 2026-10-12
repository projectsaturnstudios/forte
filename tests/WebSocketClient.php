<?php

namespace ProjectSaturnStudios\Forte\Tests;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use ProjectSaturnStudios\Forte\Promise\Deferred;
use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ratchet\RFC6455\Handshake\ResponseVerifier;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use RuntimeException;

/**
 * A WebSocket client on Forte's loop for the test suite. It speaks to the server the way
 * Pawl did: same handshake headers (Origin defaulting to the host), masked frames, pongs answered, and one 'close' event.
 *
 * Events: 'message' (string), 'ping' (FrameInterface), 'pong' (FrameInterface), 'close' (int $code, string $reason).
 */
final class WebSocketClient
{
    public ResponseInterface $response;

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    private ?MessageBuffer $frames = null;

    private string $in = '';

    private string $out = '';

    private bool $ending = false;

    private bool $closed = false;

    private bool $closeEmitted = false;

    /** @param resource $stream */
    private function __construct(private $stream, private LoopInterface $loop) {}

    /**
     * Open a connection and complete the handshake.
     *
     * @param  array<string, string>  $headers
     * @return Promise<self>
     */
    public static function connect(LoopInterface $loop, string $url, array $headers = []): Promise
    {
        $uri = new Uri($url);

        if (! in_array($uri->getScheme(), ['ws', 'wss'])) {
            return Promise::reject(new RuntimeException("Cannot connect to invalid URL ({$url})."));
        }

        $request = new Request('GET', $uri->withScheme('http'), $headers + [
            'Connection' => 'Upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            'User-Agent' => 'Forte-Tests',
            'Origin' => "http://{$uri->getHost()}",
        ]);

        $stream = @stream_socket_client("tcp://{$uri->getHost()}:".($uri->getPort() ?? 80), $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

        if ($stream === false) {
            return Promise::reject(new RuntimeException("Could not connect to {$url}: {$errstr}"));
        }

        stream_set_blocking($stream, false);

        return (new self($stream, $loop))->handshake($request, $url);
    }

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /** Send a text message, or a frame as given. */
    public function send(string|Frame $message): void
    {
        $this->frame($message instanceof Frame ? $message : new Frame($message));
    }

    /** Send a close frame, then shut the socket once it is written. */
    public function close(int $code = Frame::CLOSE_NORMAL, string $reason = ''): void
    {
        $this->frame(new Frame(pack('n', $code).$reason, true, Frame::OP_CLOSE));
        $this->emitClose($code, $reason);
        $this->end();
    }

    /** @return Promise<self> */
    private function handshake(RequestInterface $request, string $url): Promise
    {
        $deferred = new Deferred;
        $failed = function (string $why) use ($deferred, $url) {
            $this->shutdown();
            $deferred->reject(new RuntimeException("Connection to {$url} failed: {$why}"));
        };

        $this->loop->onWritable($this->stream, function () use ($request, $failed) {
            $this->loop->cancelWritable($this->stream);

            if (@stream_socket_get_name($this->stream, true) === false) {
                $failed('connection refused');

                return;
            }

            $this->write(Message::toString($request));
        });

        $this->loop->onReadable($this->stream, function () use ($request, $deferred, $failed) {
            $data = @fread($this->stream, 65536);

            if ($data === false || ($data === '' && feof($this->stream))) {
                $this->frames ? $this->shutdown() : $failed('connection closed during the handshake');

                return;
            }

            if ($this->frames) {
                $this->frames->onData($data);

                return;
            }

            $this->in .= $data;

            if (($end = strpos($this->in, "\r\n\r\n")) === false) {
                return;
            }

            $response = Message::parseResponse(substr($this->in, 0, $end + 4));
            $rest = substr($this->in, $end + 4);
            $this->in = '';

            if (! (new ResponseVerifier)->verifyAll($request, $response)) {
                $failed("the server answered {$response->getStatusCode()} {$response->getReasonPhrase()}");

                return;
            }

            $this->response = $response;
            $this->frames = new MessageBuffer(
                new CloseFrameChecker,
                fn (MessageInterface $message) => $this->emit('message', [(string) $message]),
                $this->control(...),
                expectMask: false,
                maxMessagePayloadSize: 0,
                maxFramePayloadSize: 0,
            );

            $deferred->resolve($this);

            // Frames that rode in with the 101 wait a tick, so whoever awaited the connection can listen first.
            if ($rest !== '') {
                $this->loop->addTimer(0, function () use ($rest) {
                    if (! $this->closed) {
                        $this->frames->onData($rest);
                    }
                });
            }
        });

        return $deferred->promise();
    }

    private function control(FrameInterface $frame): void
    {
        switch ($frame->getOpcode()) {
            case Frame::OP_CLOSE:
                $payload = $frame->getPayload();
                $this->emitClose(strlen($payload) >= 2 ? unpack('n', $payload)[1] : Frame::CLOSE_NORMAL, (string) substr($payload, 2));
                $this->frame(new Frame($payload, true, Frame::OP_CLOSE));
                $this->end();
                break;
            case Frame::OP_PING:
                $this->emit('ping', [$frame]);
                $this->frame(new Frame($frame->getPayload(), true, Frame::OP_PONG));
                break;
            case Frame::OP_PONG:
                $this->emit('pong', [$frame]);
                break;
        }
    }

    private function frame(Frame $frame): void
    {
        $frame->maskPayload();
        $this->write($frame->getContents());
    }

    private function write(string $bytes): void
    {
        if ($this->closed || $this->ending) {
            return;
        }

        $this->out .= $bytes;
        $this->flush();
    }

    private function flush(): void
    {
        $written = @fwrite($this->stream, $this->out);

        if ($written === false) {
            $this->shutdown();

            return;
        }

        $this->out = (string) substr($this->out, $written);

        if ($this->out === '') {
            $this->loop->cancelWritable($this->stream);

            if ($this->ending) {
                $this->shutdown();
            }

            return;
        }

        $this->loop->onWritable($this->stream, $this->flush(...));
    }

    /** Close once everything queued is written. */
    private function end(): void
    {
        $this->ending = true;

        if ($this->out === '') {
            $this->shutdown();
        }
    }

    private function shutdown(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->loop->cancelReadable($this->stream);
        $this->loop->cancelWritable($this->stream);
        @fclose($this->stream);
        $this->emitClose(Frame::CLOSE_ABNORMAL, 'Underlying connection closed');
    }

    /** Pawl emits 'close' once, with the first code it learns. */
    private function emitClose(int $code, string $reason): void
    {
        if ($this->closeEmitted) {
            return;
        }

        $this->closeEmitted = true;
        $this->emit('close', [$code, $reason]);
    }

    private function emit(string $event, array $arguments): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$arguments);
        }
    }
}
