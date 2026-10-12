<?php

namespace ProjectSaturnStudios\Forte\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;

#[AsCommand(name: 'forte:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forte:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the Forte dependencies';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->addEnvironmentVariables();
        $this->publishConfiguration();
        $this->updateBroadcastingConfiguration();
        $this->enableBroadcasting();
        $this->updateBroadcastingDriver();

        $this->components->info('Forte installed successfully.');

        $this->recommendLoopExtension();
    }

    /**
     * Point at the loop extension for this OS when it is not loaded. Never fails: install often
     * runs on a laptop that is not the server, and Forte runs on stream_select without it.
     */
    protected function recommendLoopExtension(): void
    {
        $extension = PHP_OS_FAMILY === 'Darwin' ? 'kqueue' : 'epoll';

        if (extension_loaded($extension)) {
            return;
        }

        $this->components->warn("ext-{$extension} is not loaded here. Install it on the server that runs forte:start for the production loop: pie install php-io-extensions/{$extension}");
    }

    /**
     * Add the Reverb variables to the environment file.
     */
    protected function addEnvironmentVariables(): void
    {
        if (File::missing($env = app()->environmentFile())) {
            return;
        }

        $contents = File::get($env);
        $appId = random_int(100_000, 999_999);
        $appKey = Str::lower(Str::random(20));
        $appSecret = Str::lower(Str::random(20));

        $variables = Arr::where([
            'REVERB_APP_ID' => "REVERB_APP_ID={$appId}",
            'REVERB_APP_KEY' => "REVERB_APP_KEY={$appKey}",
            'REVERB_APP_SECRET' => "REVERB_APP_SECRET={$appSecret}",
            'REVERB_HOST' => 'REVERB_HOST="localhost"',
            'REVERB_PORT' => 'REVERB_PORT=8080',
            'REVERB_SCHEME' => 'REVERB_SCHEME=http',
            'REVERB_NEW_LINE' => null,
            'VITE_REVERB_APP_KEY' => 'VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"',
            'VITE_REVERB_HOST' => 'VITE_REVERB_HOST="${REVERB_HOST}"',
            'VITE_REVERB_PORT' => 'VITE_REVERB_PORT="${REVERB_PORT}"',
            'VITE_REVERB_SCHEME' => 'VITE_REVERB_SCHEME="${REVERB_SCHEME}"',
        ], function ($value, $key) use ($contents) {
            return ! Str::contains($contents, PHP_EOL.$key);
        });

        $variables = trim(implode(PHP_EOL, $variables));

        if ($variables === '') {
            return;
        }

        File::append(
            $env,
            Str::endsWith($contents, PHP_EOL) ? PHP_EOL.$variables.PHP_EOL : PHP_EOL.PHP_EOL.$variables.PHP_EOL,
        );
    }

    /**
     * Publish the Reverb configuration file.
     */
    protected function publishConfiguration(): void
    {
        $this->callSilently('vendor:publish', [
            '--provider' => 'ProjectSaturnStudios\Forte\ForteServiceProvider',
            '--tag' => 'forte-config',
        ]);
    }

    /**
     * Update the broadcasting.php configuration file.
     */
    protected function updateBroadcastingConfiguration(): void
    {
        if ($this->laravel->config->has('broadcasting.connections.reverb')) {
            return;
        }

        File::replaceInFile(
            "'connections' => [\n",
            <<<'CONFIG'
            'connections' => [

                    'reverb' => [
                        'driver' => 'reverb',
                        'key' => env('REVERB_APP_KEY'),
                        'secret' => env('REVERB_APP_SECRET'),
                        'app_id' => env('REVERB_APP_ID'),
                        'options' => [
                            'host' => env('REVERB_HOST'),
                            'port' => env('REVERB_PORT', 443),
                            'scheme' => env('REVERB_SCHEME', 'https'),
                            'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                            'path' => env('REVERB_SERVER_PATH', ''),
                        ],
                        'client_options' => [
                            // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
                        ],
                    ],

            CONFIG,
            app()->configPath('broadcasting.php')
        );
    }

    /**
     * Enable Laravel's broadcasting functionality.
     */
    protected function enableBroadcasting(): void
    {
        $this->enableBroadcastServiceProvider();

        if (File::exists(base_path('routes/channels.php'))) {
            return;
        }

        $enable = confirm('Would you like to enable event broadcasting?', default: true);

        if (! $enable) {
            return;
        }

        if ($this->getApplication()->has('install:broadcasting')) {
            // --without-reverb: Laravel's command otherwise runs `composer require laravel/reverb`.
            $this->call('install:broadcasting', ['--no-interaction' => true, '--reverb' => true, '--without-reverb' => true]);
        }
    }

    /**
     * Uncomment the "BroadcastServiceProvider" in the application configuration.
     */
    protected function enableBroadcastServiceProvider(): void
    {
        $config = File::get(app()->configPath('app.php'));

        if (Str::contains($config, '// App\Providers\BroadcastServiceProvider::class')) {
            File::replaceInFile(
                '// App\Providers\BroadcastServiceProvider::class',
                'App\Providers\BroadcastServiceProvider::class',
                app()->configPath('app.php'),
            );
        }
    }

    /**
     * Update the configured broadcasting driver.
     */
    protected function updateBroadcastingDriver(): void
    {
        $enable = confirm('Would you like to enable the Reverb broadcasting driver?', default: true);

        if (! $enable || File::missing($env = app()->environmentFile())) {
            return;
        }

        File::put(
            $env,
            Str::of(File::get($env))->replaceMatches('/(BROADCAST_(?:DRIVER|CONNECTION))=.*/', function (array $matches) {
                return $matches[1].'=reverb';
            })
        );
    }
}
