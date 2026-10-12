<?php

namespace ProjectSaturnStudios\Forte\Protocols\Pusher\Channels;

use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\Concerns\InteractsWithPresenceChannels;

class PresenceChannel extends PrivateChannel
{
    use InteractsWithPresenceChannels;
}
