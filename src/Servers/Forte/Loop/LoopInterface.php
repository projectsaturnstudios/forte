<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Loop;

interface LoopInterface
{
    /** @param resource $stream */
    public function onReadable($stream, callable $fn): void;

    /** @param resource $stream */
    public function onWritable($stream, callable $fn): void;

    /** @param resource $stream */
    public function cancelReadable($stream): void;

    /** @param resource $stream */
    public function cancelWritable($stream): void;

    public function addTimer(float $seconds, callable $fn): Timer;

    public function addPeriodicTimer(float $seconds, callable $fn): Timer;

    public function cancelTimer(Timer $timer): void;

    public function run(): void;

    public function stop(): void;

    /** One cycle: due timers, one wait bounded by $timeout (null = until the next timer or forever), dispatch. */
    public function tick(?float $timeout = null): void;

    /** 'epoll' | 'kqueue' | 'select' */
    public function driver(): string;
}
