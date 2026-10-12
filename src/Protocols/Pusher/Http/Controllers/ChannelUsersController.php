<?php

namespace ProjectSaturnStudios\Forte\Protocols\Pusher\Http\Controllers;

use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use ProjectSaturnStudios\Forte\Protocols\Pusher\MetricsHandler;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Connection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Response;
use Psr\Http\Message\RequestInterface;

class ChannelUsersController extends Controller
{
    use InteractsWithChannelInformation;

    /**
     * Handle the request.
     */
    public function __invoke(RequestInterface $request, Connection $connection, string $channel, string $appId): Response|Promise
    {
        $this->verify($request, $connection, $appId);

        $channel = $this->channels->find($channel);

        if (! $channel) {
            return new Response((object) [], 404);
        }

        if (! $this->isPresenceChannel($channel)) {
            return new Response((object) [], 400);
        }

        return app(MetricsHandler::class)
            ->gather($this->application, 'channel_users', ['channel' => $channel->name()])
            ->then(fn ($connections) => new Response(['users' => $connections]));
    }
}
