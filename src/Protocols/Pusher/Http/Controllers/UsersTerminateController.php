<?php

namespace ProjectSaturnStudios\Forte\Protocols\Pusher\Http\Controllers;

use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\ServerProviderManager;
use ProjectSaturnStudios\Forte\Servers\Forte\Contracts\PubSubProvider;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Connection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Response;
use Psr\Http\Message\RequestInterface;

class UsersTerminateController extends Controller
{
    /**
     * Handle the request.
     */
    public function __invoke(RequestInterface $request, Connection $connection, string $appId, string $userId): Response|Promise
    {
        $this->verify($request, $connection, $appId);

        if (app(ServerProviderManager::class)->subscribesToEvents()) {
            return app(PubSubProvider::class)->publish([
                'type' => 'terminate',
                'application' => serialize($this->application),
                'payload' => ['user_id' => $userId],
            ])->then(fn () => new Response((object) []));
        }

        $connections = collect($this->channels->connections());

        $connections->each(function ($connection) use ($userId) {
            if ((string) $connection->data('user_id') === $userId) {
                $connection->disconnect();
            }
        });

        return new Response((object) []);
    }
}
