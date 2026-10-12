<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers;

use ProjectSaturnStudios\Forte\Servers\Forte\Loop\DriverInterface;
use RuntimeException;

class SelectDriver implements DriverInterface
{
    /** glibc FD_SETSIZE; stream_select() silently misbehaves above it. */
    public const MAX_DESCRIPTORS = 1024;

    public function __construct(protected int $maxDescriptors = self::MAX_DESCRIPTORS)
    {
    }

    /** @var array<int, resource> */
    protected array $read = [];

    /** @var array<int, resource> */
    protected array $write = [];

    public function watch(int $fd, $stream, int $mask): void
    {
        // A descriptor watched for both directions is one descriptor in select()'s sets.
        if (count($this->read + $this->write) >= $this->maxDescriptors) {
            throw new RuntimeException('The stream_select loop driver cannot watch more than '.$this->maxDescriptors.' descriptors; install ext-epoll or ext-kqueue.');
        }

        if ($mask & self::READ) {
            $this->read[$fd] = $stream;
        }
        if ($mask & self::WRITE) {
            $this->write[$fd] = $stream;
        }
    }

    public function modify(int $fd, int $mask): void
    {
        $stream = $this->read[$fd] ?? $this->write[$fd];
        unset($this->read[$fd], $this->write[$fd]);
        $this->watch($fd, $stream, $mask);
    }

    public function unwatch(int $fd): void
    {
        unset($this->read[$fd], $this->write[$fd]);
    }

    public function wait(?float $timeout): iterable
    {
        $read = array_values($this->read);
        $write = array_values($this->write);
        $except = null;

        if ($read === [] && $write === []) {
            if ($timeout !== null) {
                usleep((int) ($timeout * 1e6));
            }

            return [];
        }

        $seconds = $timeout === null ? null : (int) floor($timeout);
        $micro = $timeout === null ? 0 : (int) (($timeout - floor($timeout)) * 1e6);

        $ready = @stream_select($read, $write, $except, $seconds, $micro);

        if ($ready === false || $ready === 0) {
            return [];
        }

        $out = [];
        foreach ($read as $stream) {
            $out[(int) $stream] = ($out[(int) $stream] ?? 0) | self::READ;
        }
        foreach ($write as $stream) {
            $out[(int) $stream] = ($out[(int) $stream] ?? 0) | self::WRITE;
        }

        foreach ($out as $fd => $mask) {
            yield [$fd, $mask];
        }
    }

    public function name(): string
    {
        return 'select';
    }
}
