<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\InteractsWithTime;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'forte:restart')]
class RestartServer extends Command
{
    use InteractsWithTime;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forte:restart';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restart the Forte server';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        Cache::forever('laravel:forte:restart', $this->currentTime());

        $this->components->info('Broadcasting Forte restart signal.');
    }
}
