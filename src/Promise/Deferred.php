<?php

namespace ProjectSaturnStudios\Forte\Promise;

use Closure;
use Throwable;

/** A promise settled from outside: hand out promise(), call resolve() or reject() when the result is in. */
final class Deferred
{
    private Promise $promise;

    private Closure $resolve;

    private Closure $reject;

    public function __construct()
    {
        $this->promise = new Promise(function (callable $resolve, callable $reject) {
            $this->resolve = $resolve(...);
            $this->reject = $reject(...);
        });
    }

    public function promise(): Promise
    {
        return $this->promise;
    }

    public function resolve(mixed $value = null): void
    {
        ($this->resolve)($value);
    }

    public function reject(Throwable $reason): void
    {
        ($this->reject)($reason);
    }
}
