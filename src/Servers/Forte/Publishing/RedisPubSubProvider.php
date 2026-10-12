<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Publishing;

use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Servers\Forte\Contracts\PubSubIncomingMessageHandler;
use ProjectSaturnStudios\Forte\Servers\Forte\Contracts\PubSubProvider;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;

class RedisPubSubProvider implements PubSubProvider
{
    /**
     * The Redis publisher client.
     *
     * @var RedisPublishClient
     */
    protected $publisher;

    /**
     * The Redis subscriber client.
     *
     * @var RedisSubscribeClient
     */
    protected $subscriber;

    /**
     * Instantiate a new instance of the provider.
     */
    public function __construct(
        protected RedisClientFactory $clientFactory,
        protected PubSubIncomingMessageHandler $messageHandler,
        protected string $channel,
        protected array $server = []
    ) {
        //
    }

    /**
     * Connect to the publisher.
     */
    public function connect(LoopInterface $loop): void
    {
        $properties = [$loop, $this->clientFactory, $this->channel, $this->server];

        $this->publisher = new RedisPublishClient(...$properties);
        $this->subscriber = new RedisSubscribeClient(...array_merge($properties, [fn () => $this->subscribe()]));

        $this->publisher->connect();
        $this->subscriber->connect();
    }

    /**
     * Disconnect from the publisher.
     */
    public function disconnect(): void
    {
        $this->subscriber?->disconnect();
        $this->publisher?->disconnect();
    }

    /**
     * Subscribe to the publisher.
     */
    public function subscribe(): void
    {
        $this->subscriber->subscribe();

        $this->subscriber->on('message', function (string $channel, string $payload) {
            $this->messageHandler->handle($payload);
        });
    }

    /**
     * Listen for a given event.
     */
    public function on(string $event, callable $callback): void
    {
        $this->messageHandler->listen($event, $callback);
    }

    /**
     * Listen for the given event.
     *
     * @alias on
     */
    public function listen(string $event, callable $callback): void
    {
        $this->on($event, $callback);
    }

    /**
     * Stop listening for the given event..
     */
    public function stopListening(string $event): void
    {
        $this->messageHandler->stopListening($event);
    }

    /**
     * Publish a payload to the publishingClientReconnectionTimer.
     */
    public function publish(array $payload): Promise
    {
        return $this->publisher->publish($payload);
    }
}
