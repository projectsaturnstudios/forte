<?php

namespace ProjectSaturnStudios\Forte\Tests;

use ProjectSaturnStudios\Forte\Promise\Deferred;

class TestConnection
{
    /**
     * The socket ID.
     *
     * @var string
     */
    public $socketId;

    /**
     * Messages received by the connection.
     *
     * @var array<int, string>
     */
    public $receivedMessages = [];

    public $wasPinged = false;

    public $wasPonged = false;

    /**
     * Create a new test connection instance.
     */
    public function __construct(public WebSocketClient $connection)
    {
        $connection->on('message', function ($message) {
            $this->receivedMessages[] = (string) $message;
        });

        $connection->on('ping', function () {
            $this->wasPinged = true;
        });

        $connection->on('pong', function () {
            $this->wasPonged = true;
        });

        $connection->on('close', function ($code, $message) {
            $this->receivedMessages[] = (string) $message;
        });
    }

    /**
     * Get the socket ID of the connection.
     */
    public function socketId(): string
    {
        return $this->socketId;
    }

    /**
     * Await all messages to the connection to be resolved.
     */
    public function await(): mixed
    {
        $promise = new Deferred;

        $this->connection->on('message', function ($message) use ($promise) {
            $promise->resolve((string) $message);
        });

        $this->connection->on('close', function ($code, $message) use ($promise) {
            $promise->resolve((string) $message);
        });

        try {
            return await($promise->promise(), 2);
        } catch (TimeoutException) {
            return false;
        }
    }

    /**
     * Assert that the connection received the given message.
     */
    public function assertReceived(string $message, ?int $count = null): void
    {
        if ($count !== null) {
            $this->await();
        }

        // Keep waiting while other messages arrive first: under scaling an event can trail a
        // direct reply by a Redis round trip. Each await() gives up after two quiet seconds.
        while (! in_array($message, $this->receivedMessages) && $this->await() !== false) {
            //
        }

        if ($count) {
            $matches = array_filter($this->receivedMessages, fn ($m) => $m === $message);

            expect($matches)->toHaveCount($count);
        }

        expect($this->receivedMessages)->toContain($message);
    }

    /**
     * Assert that the connection did not receiv the given message.
     */
    public function assertNotReceived(string $message): void
    {
        if (! in_array($message, $this->receivedMessages)) {
            $this->await();
        }

        expect($this->receivedMessages)->not->toContain($message);
    }

    /**
     * Assert the connection was pinged during the test.
     */
    public function assertPinged(): void
    {
        $this->await();

        expect($this->wasPinged)->toBeTrue();
    }

    /**
     * Assert the connection was not pinged during the test.
     */
    public function assertNotPinged(): void
    {
        $this->await();

        expect($this->wasPinged)->toBeFalse();
    }

    /**
     * Assert the connection was ponged during the test.
     */
    public function assertPonged(): void
    {
        $this->await();

        expect($this->wasPonged)->toBeTrue();
    }

    /**
     * Assert the connection was not ponged during the test.
     */
    public function assertNotPonged(): void
    {
        $this->await();

        expect($this->wasPonged)->toBeFalse();
    }

    /**
     * Proxy method calls to the connection.
     */
    public function __call($method, $arguments)
    {
        return $this->connection->{$method}(...$arguments);
    }
}
