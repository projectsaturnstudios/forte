<?php

use Illuminate\Support\Facades\Cache;

it('broadcasts the Forte restart signal', function () {
    $this->artisan('forte:restart')
        ->expectsOutputToContain('Broadcasting Forte restart signal.')
        ->assertSuccessful();

    expect(Cache::get('laravel:forte:restart'))->not->toBeNull();
});
