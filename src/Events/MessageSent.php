<?php

namespace ProjectSaturnStudios\Forte\Events;

use Illuminate\Foundation\Events\Dispatchable;
use ProjectSaturnStudios\Forte\Contracts\Connection;

class MessageSent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Connection $connection, public string $message)
    {
        //
    }
}
