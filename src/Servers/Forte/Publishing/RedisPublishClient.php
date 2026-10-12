<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Publishing;

use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RedisConnection as Client;

class RedisPublishClient extends RedisClient
{
    /**
     * The name of the Redis connection.
     */
    protected string $name = 'publisher';

    /**
     * Stream of events queued while disconnected from Redis.
     */
    protected $queuedEvents = [];

    /**
     * Queue the given publish event.
     */
    protected function queueEvent(array $payload): void
    {
        $this->queuedEvents[] = $payload;
    }

    /**
     * Process the queued events.
     */
    protected function processQueuedEvents(): void
    {
        foreach ($this->queuedEvents as $event) {
            $this->publish($event);
        }

        $this->queuedEvents = [];
    }

    /**
     * Publish an event to the given channel.
     */
    public function publish(array $payload): Promise
    {
        if (! $this->isConnected($this->client)) {
            $this->queueEvent($payload);

            // Reverb returned a promise that never settles here; a rejection nobody handles is logged by
            // react/promise on destruct, once per event for the whole outage. The event is queued either way.
            return new Promise(fn () => null);
        }

        return $this->client->publish($this->channel, json_encode($payload));
    }

    /**
     * Handle a successful connection to the Redis server.
     */
    protected function onConnection(Client $client): void
    {
        parent::onConnection($client);

        $this->processQueuedEvents();
    }
}
