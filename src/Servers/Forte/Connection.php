<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte;

use ProjectSaturnStudios\Forte\Contracts\WebSocketConnection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Connection as HttpConnection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Request;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\DataInterface;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;

class Connection implements WebSocketConnection
{
    protected ?MessageBuffer $buffer = null;

    /** @var ?callable */
    protected $onMessage;

    /** @var ?callable */
    protected $onControl;

    /** @var ?callable */
    protected $onClose;

    protected ?int $maxMessageSize = null;

    public function __construct(public HttpConnection $connection)
    {
    }

    /**
     * Start treating incoming bytes as WebSocket frames. Anything that arrived in the same
     * read as the handshake request, past its "\r\n\r\n", is fed to the frame parser first.
     */
    public function openBuffer(): void
    {
        $this->buffer = new MessageBuffer(
            new CloseFrameChecker,
            maxMessagePayloadSize: $this->maxMessageSize,
            onMessage: $this->onMessage ?: fn () => null,
            onControl: fn (FrameInterface $message) => $this->control($message),
            sender: fn (string $bytes) => $this->connection->send($bytes),
        );

        $this->connection->onData([$this->buffer, 'onData']);
        $this->connection->onClose($this->onClose ?: fn () => null);

        $early = $this->connection->buffer();
        $this->connection->clearBuffer();

        if (($at = strpos($early, Request::EOM)) !== false && strlen($early) > $at + strlen(Request::EOM)) {
            $this->buffer->onData(substr($early, $at + strlen(Request::EOM)));
        }
    }

    public function send(mixed $message): void
    {
        $this->connection->send(
            $message instanceof DataInterface
                ? $message->getContents()
                : (new Frame($message))->getContents()
        );
    }

    public function control(FrameInterface $message): void
    {
        if ($this->onControl) {
            ($this->onControl)($message);
        }

        match ($message->getOpcode()) {
            Frame::OP_PING => $this->send(new Frame($message->getPayload(), opcode: Frame::OP_PONG)),
            Frame::OP_PONG => null,
            Frame::OP_CLOSE => $this->close($message),
            default => null,
        };
    }

    public function onMessage(callable $callback): void
    {
        $this->onMessage = $callback;
    }

    public function onControl(callable $callback): void
    {
        $this->onControl = $callback;
    }

    public function onClose(callable $callback): void
    {
        $this->onClose = $callback;

        if ($this->buffer !== null) {
            $this->connection->onClose($callback);
        }
    }

    public function withMaxMessageSize(int $size): void
    {
        $this->maxMessageSize = $size;
    }

    public function close(mixed $message = null): void
    {
        if ($message) {
            $frame = $message instanceof FrameInterface
                ? $message
                : new Frame($message, opcode: Frame::OP_CLOSE);

            $this->send($frame);
        }

        $this->connection->close();
    }

    public function id(): int
    {
        return $this->connection->id();
    }
}
