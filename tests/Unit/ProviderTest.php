<?php

use ProjectSaturnStudios\Forte\Application;
use ProjectSaturnStudios\Forte\ApplicationManager;
use ProjectSaturnStudios\Forte\Contracts\ApplicationProvider;
use ProjectSaturnStudios\Forte\Tests\FakeApplicationProvider;

it('retrieves applications from custom provider', function () {
    $this->app->make(ApplicationManager::class)->extend('fake', fn () => new FakeApplicationProvider);

    config([
        'forte.apps.provider' => 'fake',
        'forte.apps.apps' => [],
    ]);

    $applicationsProvider = $this->app->make(ApplicationProvider::class);
    $application = $applicationsProvider->all()->first();

    expect($applicationsProvider->all())->toHaveLength(1)
        ->and($application)->toBeInstanceOf(Application::class)
        ->and($application->toArray())->toMatchArray([
            'app_id' => 'id',
            'key' => 'key',
            'secret' => 'secret',
            'ping_interval' => 60,
            'allowed_origins' => ['*'],
            'max_message_size' => 10_000,
            'options' => [
                'host' => 'localhost',
                'port' => 443,
                'scheme' => 'https',
                'useTLS' => true,
            ],
        ]);
});
