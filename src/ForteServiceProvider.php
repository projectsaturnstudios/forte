<?php

namespace ProjectSaturnStudios\Forte;

use Illuminate\Support\ServiceProvider;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use Laravel\Pulse\Pulse;
use ProjectSaturnStudios\Forte\Console\Commands\InstallCommand;
use ProjectSaturnStudios\Forte\Contracts\Logger;
use ProjectSaturnStudios\Forte\Loggers\NullLogger;
use ProjectSaturnStudios\Forte\Pulse\Livewire;
use ProjectSaturnStudios\Forte\Pulse\Recorders\ForteConnections;
use ProjectSaturnStudios\Forte\Pulse\Recorders\ForteMessages;
use Livewire\LivewireManager;

class ForteServiceProvider extends ServiceProvider
{
    /**
     * Reverb's Pulse recorders, which a Reverb app's config/pulse.php names, and Forte's.
     *
     * @var array<string, class-string>
     */
    public const REVERB_RECORDERS = [
        'Laravel\\Reverb\\Pulse\\Recorders\\ReverbConnections' => ForteConnections::class,
        'Laravel\\Reverb\\Pulse\\Recorders\\ReverbMessages' => ForteMessages::class,
    ];

    private static bool $aliasesReverbRecorders = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/forte.php', 'forte'
        );

        $this->adoptReverbConfiguration();

        $this->app->instance(Logger::class, new NullLogger);

        $this->app->singleton(ServerProviderManager::class);

        // One loop per process; forte:start and Factory::make replace it with the server's own.
        $this->app->singletonIf(LoopInterface::class, fn () => Loop::create(config('forte.servers.forte.loop')));

        $this->app->make(ServerProviderManager::class)->register();

        Forte::registerDevCommands();

        // Appended after Composer's loader, so an installed laravel/reverb still loads its own classes.
        if (! self::$aliasesReverbRecorders) {
            self::$aliasesReverbRecorders = true;

            spl_autoload_register(static function (string $class): void {
                if (isset(self::REVERB_RECORDERS[$class])) {
                    class_alias(self::REVERB_RECORDERS[$class], $class);
                }
            });
        }
    }

    /**
     * Run on the app's config/reverb.php, as Reverb would, until the app publishes config/forte.php.
     */
    protected function adoptReverbConfiguration(): void
    {
        $config = $this->app['config'];
        $reverb = $config->get('reverb');

        if (! is_array($reverb) || $this->app->configurationIsCached() || file_exists($this->app->configPath('forte.php'))) {
            return;
        }

        $forte = $config->get('forte');

        if (isset($reverb['default'])) {
            $forte['default'] = $reverb['default'];
        }

        if (isset($reverb['servers']['reverb'])) {
            $forte['servers']['forte'] = array_replace_recursive($forte['servers']['forte'], $reverb['servers']['reverb']);
        }

        if (isset($reverb['apps'])) {
            $forte['apps'] = $reverb['apps'];
        }

        $config->set('forte', $forte);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(InstallCommand::class);

            $this->publishes([
                __DIR__.'/../config/forte.php' => config_path('forte.php'),
            ], ['forte', 'forte-config']);

            if (method_exists($this, 'reloads')) {
                $this->reloads('forte:restart', 'forte');
            }
        }

        if ($this->app->bound(Pulse::class)) {
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'forte');

            $this->callAfterResolving('livewire', function (LivewireManager $livewire) {
                $livewire->component('forte.messages', Livewire\Messages::class);
                $livewire->component('forte.connections', Livewire\Connections::class);

                // Reverb's names, so an existing Pulse dashboard keeps rendering after the swap.
                $livewire->component('reverb.messages', Livewire\Messages::class);
                $livewire->component('reverb.connections', Livewire\Connections::class);
            });
        }

        $this->app->make(ServerProviderManager::class)->boot();
    }
}
