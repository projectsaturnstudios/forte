<?php

use ProjectSaturnStudios\Forte\Pulse\Recorders\ForteConnections;
use ProjectSaturnStudios\Forte\Pulse\Recorders\ForteMessages;

it('resolves the recorder classes a Reverb pulse config names to Forte\'s recorders', function (string $reverb, string $forte) {
    expect(class_exists($reverb))->toBeTrue()
        ->and(is_a($reverb, $forte, true))->toBeTrue();
})->with([
    'connections' => ['Laravel\Reverb\Pulse\Recorders\ReverbConnections', ForteConnections::class],
    'messages' => ['Laravel\Reverb\Pulse\Recorders\ReverbMessages', ForteMessages::class],
]);
