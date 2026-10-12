<?php

use Evenement\EventEmitterTrait;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use ProjectSaturnStudios\Forte\Exceptions\RedisConnectionException;
use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Servers\Forte\Contracts\PubSubIncomingMessageHandler;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RedisClientFactory;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RedisConnection;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RedisPubSubProvider;

it('can successfully reconnect', function () {
    $clientFactory = Double::for(RedisClientFactory::class);
    $loop = Double::for(LoopInterface::class);

    $loop->expects('addTimer')->with(1.0, Argument::any())->returns(new ProjectSaturnStudios\Forte\Servers\Forte\Loop\Timer(1.0, false, fn () => null));

    // Publisher client, then subscriber client
    $clientFactory->expects('make')->times(2)->returns(
        new Promise(fn () => throw new Exception),
        new Promise(fn (callable $resolve) => $resolve),
    );

    $provider = new RedisPubSubProvider($clientFactory, Double::for(PubSubIncomingMessageHandler::class), 'reverb');
    $provider->connect($loop);
});

it('can timeout and fail when unable to reconnect', function () {
    $clientFactory = Double::for(RedisClientFactory::class);

    $loop = Loop::create('select');
    // run() stops on an idle loop; a watched socket keeps it turning until the retry timeout throws.
    [$keep] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $loop->onReadable($keep, fn () => null);

    // Publisher client, then subscriber client
    $clientFactory->expects('make')->times(2)->returns(
        new Promise(fn () => throw new Exception),
        new Promise(fn (callable $resolve) => $resolve),
    );

    $provider = new RedisPubSubProvider($clientFactory, Double::for(PubSubIncomingMessageHandler::class), 'reverb', ['host' => 'localhost', 'port' => 6379, 'timeout' => 1]);
    $provider->connect($loop);
    $loop->run();
    $provider->disconnect();
})->throws(RedisConnectionException::class, 'Failed to connect to Redis connection [publisher] after retrying for 1s.');

it('queues publish events', function () {
    $clientFactory = Double::for(RedisClientFactory::class);

    $clientFactory->expects('make')->times(2)->returns(new Promise(fn (callable $resolve) => $resolve));

    $provider = new RedisPubSubProvider($clientFactory, Double::for(PubSubIncomingMessageHandler::class), 'reverb');
    $provider->connect(Double::for(LoopInterface::class));
    $provider->publish(['event' => 'first test']);
    $provider->publish(['event' => 'second test']);

    $publisher = (new ReflectionProperty($provider, 'publisher'))->getValue($provider);
    $queuedEvents = (new ReflectionProperty($publisher, 'queuedEvents'))->getValue($publisher);

    expect($queuedEvents)->toBe([['event' => 'first test'], ['event' => 'second test']]);
});

it('can process queued publish events', function () {
    $clientFactory = Double::for(RedisClientFactory::class);

    $client = new class extends RedisConnection
    {
        /**
         * Commands invoked on the client, in order.
         *
         * @var array<int, array{0: string, 1: array}>
         */
        public array $calls = [];

        public function __construct()
        {
            // No socket: this double only records publishes.
        }

        public function publish(string $channel, string $payload): Promise
        {
            $this->calls[] = ['publish', [$channel, $payload]];

            return Promise::resolve(0);
        }

        public function end(): void
        {
            $this->close();
        }

        public function close(): void
        {
            $this->emit('close');
        }
    };

    // Publisher client, then subscriber client, then publisher client again
    $clientFactory->expects('make')->times(3)->returns(
        new Promise(fn (callable $resolve) => $resolve),
        new Promise(fn (callable $resolve) => $resolve),
        new Promise(fn (callable $resolve) => $resolve($client)),
    );

    $provider = new RedisPubSubProvider($clientFactory, Double::for(PubSubIncomingMessageHandler::class), 'reverb');
    $provider->connect($loop = Double::for(LoopInterface::class));
    $provider->publish(['event' => 'first test']);
    $provider->publish(['event' => 'second test']);

    $publisher = (new ReflectionProperty($provider, 'publisher'))->getValue($provider);
    $queuedEvents = (new ReflectionProperty($publisher, 'queuedEvents'))->getValue($publisher);

    expect($queuedEvents)->toHaveCount(2);

    $publisher->connect($loop);

    expect($client->listeners('close'))->toHaveCount(1);
    expect($client->calls)->toBe(
        collect($queuedEvents)->map(fn ($event) => ['publish', ['reverb', json_encode($event)]])->all()
    );
});

it('does not attempt to reconnect after a controlled disconnection', function () {
    $clientFactory = Double::for(RedisClientFactory::class);
    $loop = Loop::create('select');

    // Publisher client
    $clientFactory->expects('make')->times(2)->returns(new Promise(fn (callable $resolve) => throw new Exception));

    $provider = new RedisPubSubProvider($clientFactory, Double::for(PubSubIncomingMessageHandler::class), 'reverb');
    $loop->addTimer(1, fn () => $provider->disconnect());
    $provider->connect($loop);
});

it('queues a publish during an outage without logging an unhandled rejection', function () {
    $clientFactory = Double::for(RedisClientFactory::class);
    $clientFactory->expects('make')->times(2)->returns(new Promise(fn () => null)); // never connects

    $provider = new RedisPubSubProvider($clientFactory, Double::for(PubSubIncomingMessageHandler::class), 'reverb');
    $provider->connect(Double::for(LoopInterface::class));

    $log = tempnam(sys_get_temp_dir(), 'forte-log');
    $previous = ini_set('error_log', $log);

    try {
        // EventDispatcher discards the promise, as Reverb's callers do.
        $provider->publish(['event' => 'during outage']);
        gc_collect_cycles();
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
    }

    $publisher = (new ReflectionProperty($provider, 'publisher'))->getValue($provider);

    expect((new ReflectionProperty($publisher, 'queuedEvents'))->getValue($publisher))->toBe([['event' => 'during outage']])
        ->and(file_get_contents($log))->not->toContain('Unhandled promise rejection');

    unlink($log);
});
