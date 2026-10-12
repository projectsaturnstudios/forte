<?php

use ProjectSaturnStudios\Forte\ServerProviderManager;
use ProjectSaturnStudios\Forte\Servers\Forte\ForteServerProvider;

it('treats a default server of reverb, from a Reverb .env, as forte', function () {
    config(['forte.default' => 'reverb']);
    $manager = new ServerProviderManager(app());

    expect($manager->driver())->toBeInstanceOf(ForteServerProvider::class)
        ->and($manager->driver())->toBe($manager->driver('forte'));
});

it('reads FORTE_SERVER then REVERB_SERVER once each for the default server', function () {
    $config = file_get_contents(__DIR__.'/../../config/forte.php');

    expect($config)->toContain("'default' => env('FORTE_SERVER', env('REVERB_SERVER', 'forte')),");
});
