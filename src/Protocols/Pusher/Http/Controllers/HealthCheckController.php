<?php

namespace ProjectSaturnStudios\Forte\Protocols\Pusher\Http\Controllers;

use ProjectSaturnStudios\Forte\Servers\Forte\Http\Connection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Response;
use Psr\Http\Message\RequestInterface;

class HealthCheckController extends Controller
{
    /**
     * Handle the request.
     */
    public function __invoke(RequestInterface $request, Connection $connection): Response
    {
        return new Response((object) ['health' => 'OK']);
    }
}
