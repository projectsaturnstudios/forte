<?php

use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use ProjectSaturnStudios\Forte\Contracts\ApplicationProvider;
use ProjectSaturnStudios\Forte\Promise\Deferred;
use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Contracts\ChannelManager;
use ProjectSaturnStudios\Forte\Protocols\Pusher\MetricsHandler;
use ProjectSaturnStudios\Forte\Protocols\Pusher\MetricType;
use ProjectSaturnStudios\Forte\Protocols\Pusher\PendingMetric;
use ProjectSaturnStudios\Forte\ServerProviderManager;
use ProjectSaturnStudios\Forte\Servers\Forte\Contracts\PubSubProvider;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Timer;
use ProjectSaturnStudios\Forte\Tests\FakeConnection;

/** One Forte loop per test: the handler's ten-second gather timeout and the test's own timers share it. */
function forteLoop(bool $reset = false): LoopInterface
{
    static $loop = null;

    if ($reset || $loop === null) {
        $loop = Loop::create('select');
    }

    return $loop;
}

beforeEach(fn () => forteLoop(reset: true));

/**
 * @see https://github.com/laravel/reverb/issues/331
 */
it('removes the listener after metrics are gathered successfully', function () {
    $app = app(ApplicationProvider::class)->findByKey('reverb-key');
    app(ServerProviderManager::class)->withPublishing();
    $metric = new PendingMetric('test', $app, MetricType::CONNECTIONS);

    $stopListeningCalled = false;
    $stopListeningKey = null;
    $registeredEvent = null;

    $pubSub = Double::for(PubSubProvider::class);

    $pubSub->expects('on')->with(Argument::satisfies(fn ($event) => str_starts_with($event, 'test')), Argument::type('callable'))->resolves(function ($event, $listener) use (&$registeredListener, &$registeredEvent) {
        $registeredListener = $listener;
        $registeredEvent = $event;
    });

    $pubSub->expects('publish')->resolves(function ($payload) use (&$registeredListener) {

        forteLoop()->addTimer(0.001, function () use (&$registeredListener) {
            if ($registeredListener) {
                $registeredListener([
                    'key' => 'test',
                    'payload' => ['connections' => []],
                ]);
            }
        });

        $deferred = new Deferred;
        $deferred->resolve(1);

        return $deferred->promise();
    });

    $pubSub->expects('stopListening')->with(Argument::satisfies(function ($key) use (&$stopListeningCalled, &$stopListeningKey, &$registeredEvent) {
        $stopListeningCalled = true;
        $stopListeningKey = $key;

        return $registeredEvent === $key;
    }));

    $this->app->instance(PubSubProvider::class, $pubSub);

    $handler = new MetricsHandler(
        app(ServerProviderManager::class),
        app(ChannelManager::class),
        $pubSub,
        forteLoop()
    );

    $reflection = new ReflectionClass($handler);
    $gatherMethod = $reflection->getMethod('gatherMetricsFromSubscribers');
    $gatherMethod->setAccessible(true);

    $gatherMethod->invoke($handler, $metric);

    expect($reflection->getProperty('metrics')->getValue($handler))->toHaveKey('test');

    forteLoop()->addTimer(0.1, fn () => forteLoop()->stop());
    forteLoop()->run();

    expect($stopListeningCalled)->toBeTrue();
    expect($registeredEvent)->toBe($stopListeningKey);
    expect($reflection->getProperty('metrics')->getValue($handler))->not->toHaveKey('test');
});

it('gathers presence data from all subscribers', function () {
    $app = app(ApplicationProvider::class)->findByKey('reverb-key');
    app(ServerProviderManager::class)->withPublishing();
    $metric = new PendingMetric('test', $app, MetricType::PRESENCE_DATA, ['channel' => 'presence-test-channel']);

    $registeredListener = null;
    $gathered = null;

    $pubSub = Double::for(PubSubProvider::class);

    $pubSub->expects('on')->resolves(function ($event, $listener) use (&$registeredListener) {
        $registeredListener = $listener;
    });

    $pubSub->expects('publish')->resolves(function ($payload) use (&$registeredListener) {
        forteLoop()->addTimer(0.001, function () use (&$registeredListener) {
            $registeredListener(['key' => 'test', 'payload' => ['presence' => [
                'count' => 2,
                'ids' => [1, 2],
                'hash' => [1 => ['name' => 'Joe'], 2 => ['name' => 'Jane']],
            ]]]);

            $registeredListener(['key' => 'test', 'payload' => ['presence' => [
                'count' => 2,
                'ids' => ['2', 3, 4],
                'hash' => ['2' => ['name' => 'Jane'], 3 => ['name' => 'Jim'], 4 => []],
            ]]]);

            $registeredListener(['key' => 'test', 'payload' => []]);
        });

        $deferred = new Deferred;
        $deferred->resolve(3);

        return $deferred->promise();
    });

    $this->app->instance(PubSubProvider::class, $pubSub);

    $handler = new MetricsHandler(
        app(ServerProviderManager::class),
        app(ChannelManager::class),
        $pubSub,
        forteLoop()
    );

    $gatherMethod = (new ReflectionClass($handler))->getMethod('gatherMetricsFromSubscribers');
    $gatherMethod->setAccessible(true);

    $gatherMethod->invoke($handler, $metric)->then(function ($metrics) use (&$gathered) {
        $gathered = $metrics;
    });

    forteLoop()->addTimer(0.1, fn () => forteLoop()->stop());
    forteLoop()->run();

    expect(json_encode($gathered))->toBe(json_encode([
        'presence' => [
            'count' => 4,
            'ids' => [1, 2, 3, 4],
            'hash' => [
                1 => ['name' => 'Joe'],
                2 => ['name' => 'Jane'],
                3 => ['name' => 'Jim'],
                4 => (object) [],
            ],
        ],
    ]));
});

it('gathers empty presence data for an unknown channel', function () {
    $handler = new MetricsHandler(
        app(ServerProviderManager::class),
        app(ChannelManager::class),
        Double::for(PubSubProvider::class),
        forteLoop()
    );

    $mergeMethod = (new ReflectionClass($handler))->getMethod('mergePresenceData');
    $mergeMethod->setAccessible(true);

    expect($mergeMethod->invoke($handler, [[], []]))->toBe([
        'presence' => [
            'count' => 0,
            'ids' => [],
            'hash' => [],
        ],
    ]);
});

it('gets the connections a user holds on a presence channel', function () {
    $channel = channels()->findOrCreate('presence-test-channel');

    $first = new FakeConnection;
    $second = new FakeConnection;
    $other = new FakeConnection;

    foreach ([[$first, 1], [$second, 1], [$other, 2]] as [$connection, $userId]) {
        $data = json_encode(['user_id' => $userId]);
        $channel->subscribe($connection, validAuth($connection->id(), 'presence-test-channel', $data), $data);
    }

    $handler = new MetricsHandler(
        app(ServerProviderManager::class),
        app(ChannelManager::class),
        Double::for(PubSubProvider::class),
        forteLoop()
    );

    $connections = $handler->get(new PendingMetric('test', $first->app(), MetricType::PRESENCE_CONNECTIONS, [
        'channel' => 'presence-test-channel',
        'user_id' => '1',
    ]));

    expect($connections)->toHaveCount(2);
    expect(collect($connections)->pluck('id')->all())->toBe([$first->id(), $second->id()]);
    expect($connections[0]['subscribed_at'])->toBeFloat();
});

it('gets no presence connections for an unknown channel', function () {
    $handler = new MetricsHandler(
        app(ServerProviderManager::class),
        app(ChannelManager::class),
        Double::for(PubSubProvider::class),
        forteLoop()
    );

    $connections = $handler->get(new PendingMetric('test', app(ApplicationProvider::class)->findByKey('reverb-key'), MetricType::PRESENCE_CONNECTIONS, [
        'channel' => 'presence-test-channel',
        'user_id' => '1',
    ]));

    expect($connections)->toBe([]);
});

it('merges presence connections from all subscribers', function () {
    $handler = new MetricsHandler(
        app(ServerProviderManager::class),
        app(ChannelManager::class),
        Double::for(PubSubProvider::class),
        forteLoop()
    );

    $mergeMethod = (new ReflectionClass($handler))->getMethod('mergeSubscriberMetrics');
    $mergeMethod->setAccessible(true);

    $merged = $mergeMethod->invoke($handler, [
        [['id' => 'socket-one', 'subscribed_at' => 100.0]],
        [],
        [
            ['id' => 'socket-two', 'subscribed_at' => 100.5],
            ['id' => 'socket-three', 'subscribed_at' => 101.0],
        ],
    ], MetricType::PRESENCE_CONNECTIONS);

    expect($merged)->toBe([
        ['id' => 'socket-one', 'subscribed_at' => 100.0],
        ['id' => 'socket-two', 'subscribed_at' => 100.5],
        ['id' => 'socket-three', 'subscribed_at' => 101.0],
    ]);
});

it('merges channel users from all subscribers into a list', function () {
    $handler = new MetricsHandler(
        app(ServerProviderManager::class),
        app(ChannelManager::class),
        Double::for(PubSubProvider::class),
        forteLoop()
    );

    $mergeMethod = (new ReflectionClass($handler))->getMethod('mergeSubscriberMetrics');
    $mergeMethod->setAccessible(true);

    $merged = $mergeMethod->invoke($handler, [
        [['id' => 1], ['id' => 2]],
        [['id' => 2], ['id' => 3]],
    ], MetricType::CHANNEL_USERS);

    expect($merged)->toBe([['id' => 1], ['id' => 2], ['id' => 3]]);
});

it('resolves with the metrics that arrived when a subscriber never answers, on the Forte loop timer', function () {
    $app = app(ApplicationProvider::class)->findByKey('reverb-key');
    app(ServerProviderManager::class)->withPublishing();
    $metric = new PendingMetric('slow', $app, MetricType::CONNECTIONS);

    $loop = new class implements LoopInterface
    {
        public array $timers = [];

        public array $cancelled = [];

        public function onReadable($stream, callable $fn): void {}

        public function onWritable($stream, callable $fn): void {}

        public function cancelReadable($stream): void {}

        public function cancelWritable($stream): void {}

        public function futureTick(callable $fn): void {}

        public function addTimer(float $seconds, callable $fn): Timer
        {
            return $this->timers[] = new Timer($seconds, false, $fn(...));
        }

        public function addPeriodicTimer(float $seconds, callable $fn): Timer
        {
            return $this->addTimer($seconds, $fn);
        }

        public function cancelTimer(Timer $timer): void
        {
            $this->cancelled[] = $timer;
        }

        public function run(): void {}

        public function stop(): void {}

        public function tick(?float $timeout = null): void {}

        public function driver(): string
        {
            return 'recording';
        }
    };

    $listener = null;
    $stopped = null;
    $pubSub = Double::for(PubSubProvider::class);
    $pubSub->expects('on')->resolves(function ($event, $callback) use (&$listener) {
        $listener = $callback;
    });
    // Two subscribers are told; only one will ever answer.
    $pubSub->expects('publish')->resolves(fn () => Promise::resolve(2));
    $pubSub->expects('stopListening')->resolves(function ($key) use (&$stopped) {
        $stopped = $key;
    });

    $handler = new MetricsHandler(app(ServerProviderManager::class), app(ChannelManager::class), $pubSub, $loop);
    $gather = (new ReflectionClass($handler))->getMethod('gatherMetricsFromSubscribers');

    $gathered = null;
    $gather->invoke($handler, $metric)->then(function ($metrics) use (&$gathered) {
        $gathered = $metrics;
    });
    $listener(['key' => 'slow', 'payload' => ['socket-a' => ['id' => 'socket-a']]]);

    expect($gathered)->toBeNull()
        ->and($loop->timers)->toHaveCount(1)
        ->and($loop->timers[0]->interval)->toBe(10.0);

    ($loop->timers[0]->callback)();

    expect($gathered)->toBe(['socket-a' => ['id' => 'socket-a']])
        ->and($stopped)->toBe('slow')
        ->and($loop->cancelled)->toBe([$loop->timers[0]]);
});
