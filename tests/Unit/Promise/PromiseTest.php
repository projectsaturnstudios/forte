<?php

use ProjectSaturnStudios\Forte\Promise\Deferred;
use ProjectSaturnStudios\Forte\Promise\Promise;

function settled(Promise $promise): array
{
    $outcome = ['pending'];

    $promise->then(
        function ($value) use (&$outcome) {
            $outcome = ['fulfilled', $value];
        },
        function ($reason) use (&$outcome) {
            $outcome = ['rejected', $reason];
        },
    );

    return $outcome;
}

it('fulfills through the resolver', function () {
    expect(settled(new Promise(fn ($resolve) => $resolve('a'))))->toBe(['fulfilled', 'a']);
});

it('rejects through the resolver', function () {
    $error = new RuntimeException('no');

    expect(settled(new Promise(fn ($resolve, $reject) => $reject($error))))->toBe(['rejected', $error]);
});

it('rejects when the resolver throws', function () {
    $error = new RuntimeException('thrown');

    expect(settled(new Promise(fn () => throw $error)))->toBe(['rejected', $error]);
});

it('stays pending until the deferred settles, then runs every handler in order', function () {
    $deferred = new Deferred;
    $calls = [];

    $deferred->promise()->then(function ($value) use (&$calls) {
        $calls[] = "first {$value}";
    });
    $deferred->promise()->then(function ($value) use (&$calls) {
        $calls[] = "second {$value}";
    });

    expect($calls)->toBe([]);

    $deferred->resolve('x');

    expect($calls)->toBe(['first x', 'second x']);
});

it('settles once and ignores later resolutions and rejections', function () {
    $deferred = new Deferred;
    $deferred->resolve(1);
    $deferred->resolve(2);
    $deferred->reject(new RuntimeException);

    expect(settled($deferred->promise()))->toBe(['fulfilled', 1]);
});

it('chains the value a handler returns', function () {
    $promise = Promise::resolve(2)->then(fn ($value) => $value * 3)->then(fn ($value) => $value + 1);

    expect(settled($promise))->toBe(['fulfilled', 7]);
});

it('adopts a promise a handler returns', function () {
    $inner = new Deferred;
    $promise = Promise::resolve('outer')->then(fn () => $inner->promise());

    expect(settled($promise))->toBe(['pending']);

    $inner->resolve('inner');

    expect(settled($promise))->toBe(['fulfilled', 'inner']);
});

it('adopts a promise passed to resolve', function () {
    $deferred = new Deferred;
    $error = new RuntimeException;
    $deferred->resolve(Promise::reject($error));

    expect(settled($deferred->promise()))->toBe(['rejected', $error]);
});

it('rejects a promise resolved with itself', function () {
    $deferred = new Deferred;
    $deferred->resolve($deferred->promise());

    [$state, $reason] = settled($deferred->promise());

    expect($state)->toBe('rejected')->and($reason)->toBeInstanceOf(LogicException::class);
});

it('rejects the chain when a handler throws', function () {
    $error = new RuntimeException('handler');
    $promise = Promise::resolve(1)->then(fn () => throw $error);

    expect(settled($promise))->toBe(['rejected', $error]);
});

it('passes a value past a then without a fulfillment handler', function () {
    expect(settled(Promise::resolve('kept')->then(null, fn () => 'unused')))->toBe(['fulfilled', 'kept']);
});

it('passes a rejection past a then without a rejection handler', function () {
    $error = new RuntimeException;

    expect(settled(Promise::reject($error)->then(fn () => 'unused')))->toBe(['rejected', $error]);
});

it('recovers from a rejection with catch', function () {
    $promise = Promise::reject(new RuntimeException)->catch(fn () => 'recovered');

    expect(settled($promise))->toBe(['fulfilled', 'recovered']);
});

it('passes a value past catch', function () {
    expect(settled(Promise::resolve('kept')->catch(fn () => 'unused')))->toBe(['fulfilled', 'kept']);
});

it('runs finally on fulfillment and keeps the value', function () {
    $ran = false;
    $promise = Promise::resolve('kept')->finally(function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue()->and(settled($promise))->toBe(['fulfilled', 'kept']);
});

it('runs finally on rejection and keeps the reason', function () {
    $ran = false;
    $error = new RuntimeException;
    $promise = Promise::reject($error)->finally(function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue()->and(settled($promise))->toBe(['rejected', $error]);
});

it('rejects with the error finally throws', function () {
    $error = new RuntimeException('finally');

    expect(settled(Promise::resolve(1)->finally(fn () => throw $error)))->toBe(['rejected', $error]);
});

it('waits for a promise finally returns', function () {
    $gate = new Deferred;
    $promise = Promise::resolve('kept')->finally(fn () => $gate->promise());

    expect(settled($promise))->toBe(['pending']);

    $gate->resolve('ignored');

    expect(settled($promise))->toBe(['fulfilled', 'kept']);
});

it('returns the same promise from resolve when given one', function () {
    $promise = Promise::resolve(1);

    expect(Promise::resolve($promise))->toBe($promise);
});

it('resolves all with every value under its key, in input order', function () {
    $late = new Deferred;
    $promise = Promise::all(['a' => $late->promise(), 'b' => Promise::resolve(2), 'c' => 3]);

    expect(settled($promise))->toBe(['pending']);

    $late->resolve(1);

    expect(settled($promise))->toBe(['fulfilled', ['a' => 1, 'b' => 2, 'c' => 3]]);
});

it('resolves all of nothing to an empty array', function () {
    expect(settled(Promise::all([])))->toBe(['fulfilled', []]);
});

it('resolves all from a generator', function () {
    $items = (function () {
        yield 'x' => Promise::resolve(1);
        yield 'y' => 2;
    })();

    expect(settled(Promise::all($items)))->toBe(['fulfilled', ['x' => 1, 'y' => 2]]);
});

it('rejects all with the first rejection', function () {
    $error = new RuntimeException;
    $pending = new Deferred;

    expect(settled(Promise::all([$pending->promise(), Promise::reject($error)])))->toBe(['rejected', $error]);
});
