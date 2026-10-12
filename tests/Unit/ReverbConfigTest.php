<?php

use Illuminate\Support\Facades\File;
use ProjectSaturnStudios\Forte\ForteServiceProvider;

/** A config/reverb.php as reverb:install publishes it, with the edits an app makes. */
function publishedReverbConfig(): array
{
    return [
        'default' => 'reverb',
        'servers' => [
            'reverb' => [
                'host' => '127.0.0.1',
                'port' => 9000,
                'path' => '',
                'hostname' => 'ws.example.com',
                'options' => ['tls' => ['verify_peer' => false]],
                'max_request_size' => 20_000,
                'scaling' => ['enabled' => true, 'channel' => 'custom'],
            ],
        ],
        'apps' => [
            'provider' => 'config',
            'apps' => [
                ['key' => 'k', 'secret' => 's', 'app_id' => 'id', 'allowed_origins' => ['example.com']],
            ],
        ],
    ];
}

it('runs on the app\'s own config/reverb.php when config/forte.php is not published', function () {
    config()->set('reverb', publishedReverbConfig());

    (new ForteServiceProvider($this->app))->register();

    expect(config('forte.default'))->toBe('reverb')
        ->and(config('forte.servers.forte.port'))->toBe(9000)
        ->and(config('forte.servers.forte.hostname'))->toBe('ws.example.com')
        ->and(config('forte.servers.forte.options.tls'))->toBe(['verify_peer' => false])
        ->and(config('forte.servers.forte.scaling.channel'))->toBe('custom')
        ->and(config('forte.servers'))->not->toHaveKey('reverb')
        ->and(config('forte.apps.apps'))->toBe(publishedReverbConfig()['apps']['apps']);
});

it('keeps Forte\'s own server keys that a Reverb config does not have', function () {
    config()->set('forte.servers.forte.loop', 'select');
    config()->set('reverb', publishedReverbConfig());

    (new ForteServiceProvider($this->app))->register();

    expect(config('forte.servers.forte.loop'))->toBe('select')
        ->and(config('forte.servers.forte.scaling.server.host'))->toBe('127.0.0.1');
});

it('leaves Forte\'s config alone when the app has published config/forte.php', function () {
    $configPath = sys_get_temp_dir().'/forte-config-'.getmypid();
    File::ensureDirectoryExists($configPath);
    File::put("{$configPath}/forte.php", '<?php return [];');
    $this->app->useConfigPath($configPath);
    config()->set('reverb', publishedReverbConfig());

    (new ForteServiceProvider($this->app))->register();

    expect(config('forte.servers.forte.port'))->not->toBe(9000)
        ->and(config('forte.apps.apps.0.allowed_origins'))->toBe(['*']);

    File::deleteDirectory($configPath);
});

it('leaves Forte\'s config alone when the app has no config/reverb.php', function () {
    $before = config('forte');

    (new ForteServiceProvider($this->app))->register();

    expect(config('forte'))->toBe($before);
});
