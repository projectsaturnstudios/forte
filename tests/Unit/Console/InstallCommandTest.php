<?php

use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/forte-install-'.uniqid();
    mkdir($this->dir.'/config', 0777, true);
    mkdir($this->dir.'/routes', 0777, true);
    file_put_contents($this->dir.'/.env', "APP_NAME=Test\nBROADCAST_CONNECTION=log\n");
    file_put_contents($this->dir.'/config/app.php', "<?php\n\nreturn [];\n");
    file_put_contents($this->dir.'/config/broadcasting.php', "<?php\n\nreturn [\n    'connections' => [\n    ],\n];\n");
    $this->cwd = getcwd();
    chdir($this->dir);
    $this->app->setBasePath($this->dir);
    $this->app->useConfigPath($this->dir.'/config');
    // offsetUnset() would leave the key present as null, and has() would still see it.
    config(['broadcasting.connections' => collect(config('broadcasting.connections'))->except('reverb')->all()]);

    Artisan::command('install:broadcasting {--reverb} {--without-reverb} {--without-node}', function () {
        app()->instance('forte.test.broadcasting', $this->options());
    });
});

afterEach(function () {
    chdir($this->cwd);
    exec('rm -rf '.escapeshellarg($this->dir));
});

function installAnswers($test)
{
    return $test->artisan('forte:install')
        ->expectsConfirmation('Would you like to enable event broadcasting?', 'yes')
        ->expectsConfirmation('Would you like to enable the Reverb broadcasting driver?', 'yes');
}

it('writes the REVERB_* and VITE_REVERB_* keys Laravel and Echo read', function () {
    installAnswers($this)->assertSuccessful();

    $env = file_get_contents($this->dir.'/.env');
    foreach (['REVERB_APP_ID=', 'REVERB_APP_KEY=', 'REVERB_APP_SECRET=', 'REVERB_HOST="localhost"', 'REVERB_PORT=8080', 'REVERB_SCHEME=http', 'VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"', 'VITE_REVERB_HOST="${REVERB_HOST}"', 'VITE_REVERB_PORT="${REVERB_PORT}"', 'VITE_REVERB_SCHEME="${REVERB_SCHEME}"'] as $line) {
        expect($env)->toContain($line);
    }
    expect($env)->toContain('BROADCAST_CONNECTION=reverb')->not->toContain('FORTE_APP_KEY');
});

it('publishes config/forte.php and adds the reverb broadcasting connection', function () {
    // The provider fixes its publish destination at boot, before this test repoints the config path.
    $published = array_values(Illuminate\Support\ServiceProvider::pathsToPublish(ProjectSaturnStudios\Forte\ForteServiceProvider::class, 'forte-config'))[0];
    @unlink($published);

    installAnswers($this)->assertSuccessful();

    expect(file_exists($published))->toBeTrue()
        ->and(file_get_contents($this->dir.'/config/broadcasting.php'))->toContain("'driver' => 'reverb'");

    unlink($published);
});

it('asks install:broadcasting for the reverb driver without installing laravel/reverb', function () {
    installAnswers($this)->assertSuccessful();

    $options = app('forte.test.broadcasting');

    expect($options['reverb'])->toBeTrue()->and($options['without-reverb'])->toBeTrue();
});

it('does not duplicate keys when run twice', function () {
    installAnswers($this)->assertSuccessful();
    // The fake install:broadcasting never creates routes/channels.php; the real one would, so remove it if present.
    @unlink($this->dir.'/routes/channels.php');
    // A second artisan run reloads config/broadcasting.php; this process has to be told.
    config(['broadcasting.connections.reverb' => ['driver' => 'reverb']]);
    installAnswers($this)->assertSuccessful();

    expect(preg_match_all('/^REVERB_APP_KEY=/m', file_get_contents($this->dir.'/.env')))->toBe(1)
        ->and(substr_count(file_get_contents($this->dir.'/config/broadcasting.php'), "'driver' => 'reverb'"))->toBe(1);
});

it('does not fail without a .env file', function () {
    unlink($this->dir.'/.env');

    $this->artisan('forte:install')
        ->expectsConfirmation('Would you like to enable event broadcasting?', 'yes')
        ->expectsConfirmation('Would you like to enable the Reverb broadcasting driver?', 'yes')
        ->assertSuccessful();
});

it('points at the loop extension for this OS when it is not loaded', function () {
    $extension = PHP_OS_FAMILY === 'Darwin' ? 'kqueue' : 'epoll';
    $command = installAnswers($this);

    if (extension_loaded($extension)) {
        $command->doesntExpectOutputToContain('pie install');
    } else {
        $command->expectsOutputToContain("pie install php-io-extensions/{$extension}");
    }

    $command->assertSuccessful();
});

afterAll(function () {
    // vendor:publish writes into testbench's skeleton (see the publish test); leave it as found.
    @unlink(dirname(__DIR__, 3).'/vendor/orchestra/testbench-core/laravel/config/forte.php');
});
