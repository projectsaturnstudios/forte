<?php

namespace ProjectSaturnStudios\Forte\Protocols\Pusher\Http\Controllers;

use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Protocols\Pusher\MetricsHandler;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Connection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Response;
use Psr\Http\Message\RequestInterface;

class ChannelsController extends Controller
{
    /**
     * Handle the request.
     */
    public function __invoke(RequestInterface $request, Connection $connection, string $appId): Promise
    {
        $this->verify($request, $connection, $appId);

        return app(MetricsHandler::class)->gather($this->application, 'channels', [
            'filter' => $this->query['filter_by_prefix'] ?? null,
            'info' => $this->query['info'] ?? null,
        ])->then(fn ($channels) => new Response(['channels' => array_map(fn ($item) => (object) $item, $channels)]));
    }
}
