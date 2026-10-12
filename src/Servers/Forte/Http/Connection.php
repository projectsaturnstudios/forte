<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Http;

use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;

class Connection
{
    public const MAX_WRITE_BUFFER = 1_048_576;

    protected const READ_CHUNK = 8192;

    protected int $id;

    protected bool $connected = false;

    /** The HTTP request buffer Reverb's Request::from() fills while a request is incomplete. */
    protected string $buffer = '';

    protected string $writeBuffer = '';

    protected bool $closing = false;

    protected bool $closed = false;

    protected bool $handshaking;

    /** @var ?callable(string): void */
    protected $onData = null;

    /** @var ?callable(): void */
    protected $onClose = null;

    /** @param resource $stream a non-blocking accepted socket */
    public function __construct(
        protected $stream,
        protected LoopInterface $loop,
        protected bool $tls = false,
        protected int $maxWriteBuffer = self::MAX_WRITE_BUFFER,
    )
    {
        $this->id = (int) $stream;
        $this->handshaking = $tls;

        // During a TLS handshake only readable is armed: an idle socket is always writable, so arming
        // writable would wake a level-triggered loop on every pass. OpenSSL writes its own flights
        // synchronously inside stream_socket_enable_crypto() (React's StreamEncryption does the same).
        $this->loop->onReadable($stream, $this->handleReadable(...));
    }

    public function id(): int
    {
        return $this->id;
    }

    /** @return resource|false the stream, or false once closed */
    public function stream()
    {
        return $this->closed ? false : $this->stream;
    }

    public function isSecure(): bool
    {
        return $this->tls;
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function buffer(): string
    {
        return $this->buffer;
    }

    public function hasBuffer(): bool
    {
        return $this->buffer !== '';
    }

    public function bufferLength(): int
    {
        return strlen($this->buffer);
    }

    public function appendToBuffer(string $message): void
    {
        $this->buffer .= $message;
    }

    public function clearBuffer(): void
    {
        $this->buffer = '';
    }

    public function onData(callable $callback): void
    {
        $this->onData = $callback;
    }

    public function onClose(callable $callback): void
    {
        $this->onClose = $callback;
    }

    public function send(string $data): self
    {
        if ($this->closed || $this->closing) {
            return $this;
        }

        if ($this->writeBuffer === '' && ! $this->handshaking) {
            $written = @fwrite($this->stream, $data);

            if ($written === false) {
                $this->terminate();

                return $this;
            }

            $data = (string) substr($data, $written);
        }

        if ($data !== '') {
            $this->writeBuffer .= $data;

            if (strlen($this->writeBuffer) > $this->maxWriteBuffer) {
                $this->terminate();

                return $this;
            }

            if (! $this->handshaking) {
                $this->loop->onWritable($this->stream, $this->handleWritable(...));
            }
        }

        return $this;
    }

    /** Alias Reverb's Router calls during the WebSocket upgrade. */
    public function write(string $data): void
    {
        $this->send($data);
    }

    /** Close after pending output has been flushed. */
    public function close(): self
    {
        if ($this->closed) {
            return $this;
        }

        $this->closing = true;

        if ($this->writeBuffer === '') {
            $this->terminate();
        }

        return $this;
    }

    public function handleReadable(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->handshaking) {
            $this->continueHandshake();

            return;
        }

        // Plain TCP: one read per wakeup; a level-triggered loop reports what is left next pass.
        // TLS: OpenSSL decrypts whole records into its own buffer, so bytes past this read can sit
        // there with the kernel socket empty and no further wakeup. Drain until a short read.
        do {
            $data = @fread($this->stream, self::READ_CHUNK);

            if ($data === false || ($data === '' && feof($this->stream))) {
                $this->terminate();

                return;
            }

            if ($data !== '' && $this->onData) {
                ($this->onData)($data);
            }
        } while ($this->tls && ! $this->closed && strlen($data) === self::READ_CHUNK);
    }

    public function handleWritable(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->handshaking) {
            $this->continueHandshake();

            return;
        }

        if ($this->writeBuffer !== '') {
            $written = @fwrite($this->stream, $this->writeBuffer);

            if ($written === false) {
                $this->terminate();

                return;
            }

            $this->writeBuffer = (string) substr($this->writeBuffer, $written);
        }

        if ($this->writeBuffer === '') {
            $this->loop->cancelWritable($this->stream);

            if ($this->closing) {
                $this->terminate();
            }
        }
    }

    protected function continueHandshake(): void
    {
        $result = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

        if ($result === false) {
            $this->terminate();

            return;
        }

        if ($result === true) {
            $this->handshaking = false;

            if ($this->writeBuffer !== '') {
                $this->loop->onWritable($this->stream, $this->handleWritable(...));
            }

            // The client's first application data may already be decrypted inside OpenSSL.
            $this->handleReadable();
        }
        // 0: not yet; the client's next flight wakes the readable watch.
    }

    /** Tear down now: unwatch, close the stream, fire onClose exactly once. */
    protected function terminate(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->loop->cancelReadable($this->stream);
        $this->loop->cancelWritable($this->stream);
        @fclose($this->stream);

        if ($this->onClose) {
            ($this->onClose)();
        }
    }
}
