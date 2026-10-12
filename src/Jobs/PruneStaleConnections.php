<?php

namespace ProjectSaturnStudios\Forte\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use ProjectSaturnStudios\Forte\Contracts\ApplicationProvider;
use ProjectSaturnStudios\Forte\Events\ConnectionPruned;
use ProjectSaturnStudios\Forte\Loggers\Log;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Contracts\ChannelManager;

class PruneStaleConnections
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(ChannelManager $channels): void
    {
        Log::info('Pruning Stale Connections');

        app(ApplicationProvider::class)
            ->all()
            ->each(function ($application) use ($channels) {
                foreach ($channels->for($application)->connections() as $connection) {
                    if (! $connection->isStale()) {
                        continue;
                    }

                    $connection->send(json_encode([
                        'event' => 'pusher:error',
                        'data' => json_encode([
                            'code' => 4201,
                            'message' => 'Pong reply not received in time',
                        ]),
                    ]));

                    $channels
                        ->for($connection->app())
                        ->unsubscribeFromAll($connection->connection());

                    $connection->disconnect();

                    Log::info('Connection Pruned', $connection->id());

                    ConnectionPruned::dispatch($connection);
                }
            });
    }
}
