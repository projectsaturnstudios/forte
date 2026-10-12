<?php

namespace ProjectSaturnStudios\Forte;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Manager;
use ProjectSaturnStudios\Forte\Servers\Forte\ForteServerProvider;

class ServerProviderManager extends Manager
{
    /**
     * Create a new server manager instance.
     */
    public function __construct(protected Application $app)
    {
        parent::__construct($app);
    }

    /**
     * Creates the Reverb driver.
     */
    public function createForteDriver(): ForteServerProvider
    {
        return new ForteServerProvider(
            $this->app,
            $this->config->get('forte.servers.forte', [])
        );
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        $default = $this->config->get('forte.default', 'forte');

        // A .env carried over from Reverb says REVERB_SERVER=reverb; Forte is that server.
        return $default === 'reverb' ? 'forte' : $default;
    }
}
