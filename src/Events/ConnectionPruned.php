<?php

namespace ProjectSaturnStudios\Forte\Events;

use Illuminate\Foundation\Events\Dispatchable;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\ChannelConnection;

class ConnectionPruned
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public ChannelConnection $connection)
    {
        //
    }
}
