<?php

namespace ProjectSaturnStudios\Forte\Events;

use Illuminate\Foundation\Events\Dispatchable;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\Channel;

class ChannelRemoved
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Channel $channel)
    {
        //
    }
}
