<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Connection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Route;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Router;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Server;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

require __DIR__.'/../../vendor/autoload.php';

// An echo server for the Autobahn fuzzing client: every message comes straight back.
$loop = Loop::create(getenv('FORTE_LOOP') ?: null);

$routes = new RouteCollection;
$routes->add('sockets', Route::get('/', function (RequestInterface $request, Connection $connection) {
    $connection->onMessage(fn ($message) => $connection->send($message));
    $connection->openBuffer();
}));

// Autobahn's 9.* cases echo messages up to 16 MiB; Forte's default 1 MiB write cap would drop them.
$server = new Server($loop, 'tcp://0.0.0.0:8080', [], new Router(new UrlMatcher($routes, new RequestContext)), 10_000, 64 * 1_048_576);

echo "Server running at 0.0.0.0:8080 ({$loop->driver()})\n";

$server->start();
