<?php

namespace ProjectSaturnStudios\Forte\Protocols\Pusher\Http\Controllers;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use ProjectSaturnStudios\Forte\Protocols\Pusher\EventDispatcher;
use ProjectSaturnStudios\Forte\Protocols\Pusher\MetricsHandler;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Connection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Response;
use Psr\Http\Message\RequestInterface;

class EventsBatchController extends Controller
{
    use InteractsWithChannelInformation;

    /**
     * Handle the request.
     */
    public function __invoke(RequestInterface $request, Connection $connection, string $appId): Response|Promise
    {
        $this->verify($request, $connection, $appId);

        $payload = json_decode($this->body, associative: true, flags: JSON_THROW_ON_ERROR);

        $validator = $this->validator($payload);

        if ($validator->fails()) {
            return new Response($validator->errors(), 422);
        }

        $items = collect($payload['batch']);

        $items = $items->map(function ($item) {
            $except = isset($item['socket_id']) ? ($this->channels->connections()[$item['socket_id']] ?? null) : null;

            EventDispatcher::dispatch(
                $this->application,
                [
                    'event' => $item['name'],
                    'channel' => $item['channel'],
                    'data' => $item['data'],
                ],
                $except ? $except->connection() : null,
                $item['socket_id'] ?? null,
            );

            return isset($item['info']) ? app(MetricsHandler::class)->gather(
                $this->application,
                'channel',
                ['channel' => $item['channel'], 'info' => $item['info']]
            ) : [];
        });

        if ($items->contains(fn ($item) => ! empty($item))) {
            return Promise::all($items)->then(function ($items) {
                return new Response(['batch' => array_map(fn ($item) => (object) $item, $items)]);
            });
        }

        return new Response(['batch' => (object) []]);
    }

    /**
     * Get the info for the given channels.
     *
     * @return array<string, array<string, int>>
     */
    protected function getInfo(string $channel, string $info): array
    {
        $info = explode(',', $info);
        $count = count($this->channels->find($channel)->connections());
        $info = [
            'user_count' => in_array('user_count', $info) ? $count : null,
            'subscription_count' => in_array('subscription_count', $info) ? $count : null,
        ];

        return array_filter($info, fn ($item) => $item !== null);
    }

    /**
     * Create a validator for the incoming request payload.
     */
    protected function validator(array $payload): Validator
    {
        return ValidatorFacade::make($payload, [
            'batch' => ['required', 'array'],
            'batch.*.name' => ['required', 'string'],
            'batch.*.data' => ['required', 'string'],
            'batch.*.channel' => ['required_without:channels', 'string'],
            'batch.*.socket_id' => ['string'],
            'batch.*.info' => ['string'],
        ]);
    }
}
