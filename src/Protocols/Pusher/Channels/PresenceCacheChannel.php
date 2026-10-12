<?php

namespace ProjectSaturnStudios\Forte\Protocols\Pusher\Channels;

use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\Concerns\InteractsWithPresenceChannels;

class PresenceCacheChannel extends CacheChannel
{
    use InteractsWithPresenceChannels;
}
