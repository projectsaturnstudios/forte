<?php

namespace ProjectSaturnStudios\Forte\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use ProjectSaturnStudios\Forte\Contracts\ApplicationProvider;
use ProjectSaturnStudios\Forte\Loggers\Log;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Contracts\ChannelManager;
use ProjectSaturnStudios\Forte\Protocols\Pusher\EventHandler;

class PingInactiveConnections
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(ChannelManager $channels): void
    {
        Log::info('Pinging Inactive Connections');

        $pusher = new EventHandler($channels);

        app(ApplicationProvider::class)
            ->all()
            ->each(function ($application) use ($channels, $pusher) {
                foreach ($channels->for($application)->connections() as $connection) {
                    if ($connection->isActive()) {
                        continue;
                    }

                    $pusher->ping($connection->connection());

                    Log::info('Connection Pinged', $connection->id());
                }
            });
    }
}
