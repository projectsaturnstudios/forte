<?php

namespace ProjectSaturnStudios\Forte\Promise;

use LogicException;
use Throwable;

/**
 * A promise that settles synchronously: handlers run the moment it settles, or at once when
 * attached to a settled promise. The loop drives the I/O; promises only carry its results.
 */
class Promise
{
    private const PENDING = 0;

    private const FULFILLED = 1;

    private const REJECTED = 2;

    private int $state = self::PENDING;

    private mixed $result = null;

    /** @var list<callable(): void> */
    private array $handlers = [];

    /** @param callable(callable(mixed=): void, callable(Throwable): void): void $resolver */
    public function __construct(callable $resolver)
    {
        try {
            $resolver($this->fulfill(...), $this->rejectWith(...));
        } catch (Throwable $e) {
            $this->rejectWith($e);
        }
    }

    public static function resolve(mixed $value = null): self
    {
        return $value instanceof self ? $value : new self(fn (callable $resolve) => $resolve($value));
    }

    public static function reject(Throwable $reason): self
    {
        return new self(fn (callable $resolve, callable $reject) => $reject($reason));
    }

    /**
     * Fulfill with every value under its key, in input order, once all fulfill; reject with the first rejection.
     *
     * @param  iterable<mixed>  $promisesOrValues
     */
    public static function all(iterable $promisesOrValues): self
    {
        $items = is_array($promisesOrValues) ? $promisesOrValues : iterator_to_array($promisesOrValues);

        return new self(function (callable $resolve, callable $reject) use ($items) {
            $remaining = count($items);
            $values = array_fill_keys(array_keys($items), null);

            if ($remaining === 0) {
                $resolve([]);

                return;
            }

            foreach ($items as $key => $item) {
                self::resolve($item)->then(function (mixed $value) use ($key, &$values, &$remaining, $resolve) {
                    $values[$key] = $value;

                    if (--$remaining === 0) {
                        $resolve($values);
                    }
                }, $reject);
            }
        });
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        return new self(function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
            $handler = function () use ($onFulfilled, $onRejected, $resolve, $reject) {
                $callback = $this->state === self::FULFILLED ? $onFulfilled : $onRejected;

                if ($callback === null) {
                    $this->state === self::FULFILLED ? $resolve($this->result) : $reject($this->result);

                    return;
                }

                try {
                    $resolve($callback($this->result));
                } catch (Throwable $e) {
                    $reject($e);
                }
            };

            $this->state === self::PENDING ? $this->handlers[] = $handler : $handler();
        });
    }

    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /** Run $onSettled either way, then pass the value or reason through; waits for a promise it returns. */
    public function finally(callable $onSettled): self
    {
        return $this->then(
            fn (mixed $value) => self::resolve($onSettled())->then(fn () => $value),
            fn (Throwable $reason) => self::resolve($onSettled())->then(fn () => throw $reason),
        );
    }

    private function fulfill(mixed $value = null): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        if ($value === $this) {
            $this->rejectWith(new LogicException('A promise cannot be resolved with itself.'));

            return;
        }

        if ($value instanceof self) {
            $value->then($this->fulfill(...), $this->rejectWith(...));

            return;
        }

        $this->settle(self::FULFILLED, $value);
    }

    private function rejectWith(Throwable $reason): void
    {
        if ($this->state === self::PENDING) {
            $this->settle(self::REJECTED, $reason);
        }
    }

    private function settle(int $state, mixed $result): void
    {
        $this->state = $state;
        $this->result = $result;
        $handlers = $this->handlers;
        $this->handlers = [];

        foreach ($handlers as $handler) {
            $handler();
        }
    }
}
