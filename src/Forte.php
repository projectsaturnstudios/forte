<?php

namespace ProjectSaturnStudios\Forte;

use Illuminate\Foundation\DevCommands;

class Forte
{
    /**
     * Register the Reverb dev commands.
     */
    public static function registerDevCommands(): void
    {
        if (class_exists(DevCommands::class)) {
            DevCommands::artisan('forte:start', 'forte');
        }
    }
}
