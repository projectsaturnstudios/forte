<?php

namespace ProjectSaturnStudios\Forte\Protocols\Pusher\Channels;

use ProjectSaturnStudios\Forte\Protocols\Pusher\Channels\Concerns\InteractsWithPrivateChannels;

class PrivateChannel extends Channel
{
    use InteractsWithPrivateChannels;
}
