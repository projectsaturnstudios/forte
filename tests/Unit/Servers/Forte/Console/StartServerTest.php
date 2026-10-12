<?php

use Illuminate\Support\Facades\Cache;

it('starts on the chosen driver, warns about select, and stops on the restart signal', function () {
    Cache::put('laravel:forte:restart', 'before');

    // The command polls the restart key every 5 s. Flip it from a signal one second in:
    // the alarm interrupts the loop's wait, the handler runs, the next poll sees the change.
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, fn () => Cache::put('laravel:forte:restart', 'after'), restart_syscalls: false);
    pcntl_alarm(1);

    try {
        $this->artisan('forte:start', ['--host' => '127.0.0.1', '--port' => '0', '--loop' => 'select'])
            ->expectsOutputToContain('(select)')
            ->expectsOutputToContain('Running on stream_select')
            ->assertSuccessful();
    } finally {
        pcntl_signal(SIGALRM, SIG_DFL);
    }
})->skip(fn () => ! function_exists('pcntl_alarm'), 'needs pcntl');
