<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Http\Connection;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Route;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Router;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Server;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\SelectDriver;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

function httpServer(): array
{
    $loop = new Loop(new SelectDriver);
    $routes = new RouteCollection;
    $routes->add('up', Route::get('/up', fn () => new Symfony\Component\HttpFoundation\Response('ok', 200, ['Content-Length' => '2'])));
    $routes->add('echo', Route::post('/echo', fn (RequestInterface $request) => new Symfony\Component\HttpFoundation\Response((string) $request->getBody(), 200)));
    $router = new Router(new UrlMatcher($routes, new RequestContext));
    $server = new Server($loop, 'tcp://127.0.0.1:0', [], $router, 1000);

    return [$loop, $server];
}

function rawHttp(Loop $loop, Server $server, string $request): string
{
    $client = stream_socket_client('tcp://'.$server->address(), $errno, $errstr, 1);
    stream_set_blocking($client, false);
    fwrite($client, $request);
    $response = '';
    for ($i = 0; $i < 200; $i++) {
        $loop->tick(0.0);
        $response .= (string) stream_get_contents($client);
        if (feof($client)) {
            break;
        }
        usleep(1000);
    }

    return $response;
}

it('answers a routed GET and closes the connection', function () {
    [$loop, $server] = httpServer();

    $response = rawHttp($loop, $server, "GET /up HTTP/1.1\r\nHost: localhost\r\n\r\n");

    expect($response)->toStartWith('HTTP/1.0 200 OK')->toEndWith('ok');
    $server->stop();
});

it('answers 404 for an unknown path', function () {
    [$loop, $server] = httpServer();

    expect(rawHttp($loop, $server, "GET /nope HTTP/1.1\r\nHost: localhost\r\n\r\n"))->toStartWith('HTTP/1.1 404');
    $server->stop();
});

it('answers 413 when the request exceeds the maximum size', function () {
    [$loop, $server] = httpServer();

    expect(rawHttp($loop, $server, "GET /up HTTP/1.1\r\nX-Pad: ".str_repeat('a', 1001)."\r\n\r\n"))->toStartWith('HTTP/1.1 413');
    $server->stop();
});

it('waits for the whole body before dispatching a POST', function () {
    [$loop, $server] = httpServer();
    $client = stream_socket_client('tcp://'.$server->address(), $errno, $errstr, 1);
    stream_set_blocking($client, false);
    fwrite($client, "POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\n\r\nhel");
    $loop->tick(0.0);
    $loop->tick(0.0);
    expect(stream_get_contents($client))->toBe('');
    fwrite($client, 'lo');
    $response = '';
    for ($i = 0; $i < 200 && ! feof($client); $i++) {
        $loop->tick(0.0);
        $response .= (string) stream_get_contents($client);
        usleep(1000);
    }

    expect($response)->toEndWith('hello');
    $server->stop();
});

it('accepts a burst of connections in one pass', function () {
    [$loop, $server] = httpServer();
    $clients = [];
    for ($i = 0; $i < 20; $i++) {
        $clients[] = $c = stream_socket_client('tcp://'.$server->address(), $errno, $errstr, 1);
        stream_set_blocking($c, false);
        fwrite($c, "GET /up HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }
    $done = 0;
    for ($i = 0; $i < 200 && $done < 20; $i++) {
        $loop->tick(0.0);
        $done = count(array_filter($clients, fn ($c) => str_contains((string) stream_get_contents($c), '200') || feof($c)));
        usleep(1000);
    }

    expect($done)->toBe(20);
    $server->stop();
});

it('reports whether it is secure', function () {
    [, $server] = httpServer();
    expect($server->isSecure())->toBeFalse();
    $server->stop();
});

it('can be stopped twice', function () {
    [, $server] = httpServer();

    $server->stop();
    $server->stop();

    expect(fn () => stream_socket_client('tcp://'.$server->address(), $errno, $errstr, 0.2))->toThrow(ErrorException::class);
});

it('refuses a connection over the select ceiling and keeps serving the others', function () {
    $loop = new Loop(new SelectDriver(maxDescriptors: 3)); // the listener plus two connections
    $routes = new RouteCollection;
    $routes->add('up', Route::get('/up', fn () => new Symfony\Component\HttpFoundation\Response('ok', 200)));
    $server = new Server($loop, 'tcp://127.0.0.1:0', [], new Router(new UrlMatcher($routes, new RequestContext)), 1000);

    $clients = [];
    for ($i = 0; $i < 3; $i++) {
        $clients[$i] = stream_socket_client('tcp://'.$server->address(), $errno, $errstr, 1);
        stream_set_blocking($clients[$i], false);
        $loop->tick(0.0);
        usleep(2000);
    }
    for ($i = 0; $i < 20; $i++) {
        $loop->tick(0.0);
    }

    expect(fread($clients[2], 10))->toBe('')->and(feof($clients[2]))->toBeTrue();

    fwrite($clients[0], "GET /up HTTP/1.1\r\nHost: localhost\r\n\r\n");
    $response = '';
    for ($i = 0; $i < 200 && ! feof($clients[0]); $i++) {
        $loop->tick(0.0);
        $response .= (string) fread($clients[0], 1000);
        usleep(1000);
    }

    expect($response)->toContain('200 OK');
    $server->stop();
});

it('queues a burst of connects the loop has not accepted yet, as React\'s 511 backlog does', function () {
    [$loop, $server] = httpServer();
    $connected = 0;
    $clients = [];

    // 100 clients connect before the loop runs once: they wait in the listen queue.
    for ($i = 0; $i < 100; $i++) {
        $client = @stream_socket_client('tcp://'.$server->address(), $errno, $errstr, 0.5);
        if ($client !== false) {
            $clients[] = $client;
            $connected++;
        }
    }

    expect($connected)->toBe(100);
    $server->stop();
});
