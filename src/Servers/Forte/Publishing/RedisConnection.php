<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Publishing;

use ProjectSaturnStudios\Forte\Promise\Deferred;
use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use RuntimeException;

/**
 * One Redis socket on the loop: a FIFO of pending command replies, plus
 * push messages once SUBSCRIBE has been sent.
 */
class RedisConnection
{
    /** @var array<string, list<callable>> */
    protected array $listeners = [];

    protected Resp $resp;

    /** @var list<Deferred> replies are matched to commands in order */
    protected array $pending = [];

    protected string $writeBuffer = '';

    protected bool $subscribed = false;

    protected bool $closed = false;

    /** @param resource $stream a connected, non-blocking socket */
    public function __construct(protected $stream, protected LoopInterface $loop)
    {
        $this->resp = new Resp;
        $this->loop->onReadable($stream, $this->handleReadable(...));
    }

    /** @return Promise<int> the number of subscribers that received the message */
    public function publish(string $channel, string $payload): Promise
    {
        return $this->command(['PUBLISH', $channel, $payload]);
    }

    public function subscribe(string $channel): Promise
    {
        $this->subscribed = true;

        return $this->command(['SUBSCRIBE', $channel]);
    }

    public function auth(?string $username, string $password): Promise
    {
        return $this->command($username !== null && $username !== '' ? ['AUTH', $username, $password] : ['AUTH', $password]);
    }

    public function select(int $database): Promise
    {
        return $this->command(['SELECT', (string) $database]);
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

        foreach ($this->pending as $deferred) {
            $deferred->reject(new RuntimeException('Redis connection closed.'));
        }
        $this->pending = [];

        $this->emit('close');
    }

    /** clue/redis-react name Reverb's code called. */
    public function end(): void
    {
        $this->close();
    }

    /** Listen for 'message' (channel, payload) or 'close'. */
    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /** @return list<callable> */
    public function listeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    protected function emit(string $event, array $arguments = []): void
    {
        foreach ($this->listeners($event) as $listener) {
            $listener(...$arguments);
        }
    }

    protected function command(array $parts): Promise
    {
        if ($this->closed) {
            return Promise::reject(new RuntimeException('Redis connection closed.'));
        }

        $deferred = new Deferred;
        $this->pending[] = $deferred;
        $this->write(Resp::encode($parts));

        return $deferred->promise();
    }

    protected function write(string $bytes): void
    {
        if ($this->writeBuffer === '') {
            $written = @fwrite($this->stream, $bytes);
            if ($written === false) {
                $this->close();

                return;
            }
            $bytes = (string) substr($bytes, $written);
        }

        if ($bytes !== '') {
            $this->writeBuffer .= $bytes;
            $this->loop->onWritable($this->stream, $this->handleWritable(...));
        }
    }

    protected function handleWritable(): void
    {
        $written = @fwrite($this->stream, $this->writeBuffer);
        if ($written === false) {
            $this->close();

            return;
        }
        $this->writeBuffer = (string) substr($this->writeBuffer, $written);
        if ($this->writeBuffer === '') {
            $this->loop->cancelWritable($this->stream);
        }
    }

    protected function handleReadable(): void
    {
        $data = @fread($this->stream, 65536);

        if ($data === false || ($data === '' && feof($this->stream))) {
            $this->close();

            return;
        }

        $this->resp->feed($data);

        while (($value = $this->resp->next()) !== Resp::INCOMPLETE) {
            $this->dispatch($value);
        }
    }

    protected function dispatch(mixed $value): void
    {
        if ($this->subscribed && is_array($value) && ($value[0] ?? null) === 'message') {
            $this->emit('message', [$value[1], $value[2]]);

            return;
        }

        $deferred = array_shift($this->pending);

        if ($deferred === null) {
            return; // an unsolicited reply; nothing is waiting for it
        }

        if ($value instanceof RespError) {
            $deferred->reject(new RuntimeException($value->message));

            return;
        }

        // SUBSCRIBE answers ["subscribe", channel, count]; resolve with the count like clue/redis-react did.
        $deferred->resolve(is_array($value) && ($value[0] ?? null) === 'subscribe' ? $value[2] : $value);
    }
}
