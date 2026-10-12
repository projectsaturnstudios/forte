<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Publishing;

class RedisSubscribeClient extends RedisClient
{
    /**
     * The name of the Redis connection.
     */
    protected string $name = 'subscriber';

    /**
     * Subscribe to the given Redis channel.
     */
    public function subscribe(): void
    {
        $this->client->subscribe($this->channel);
    }
}
