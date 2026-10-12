<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Http\Route;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Router;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Server;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\EpollDriver;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\KqueueDriver;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\SelectDriver;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

dataset('tls drivers', [
    'select' => [fn () => new SelectDriver],
    'epoll' => [fn () => function_exists('epoll_create1') ? new EpollDriver : null],
    'kqueue' => [fn () => function_exists('kqueue') ? new KqueueDriver : null],
]);

function selfSignedPem(): string
{
    static $path = null;

    if ($path === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $key), null, $key, 1);
        openssl_x509_export($x509, $cert);
        openssl_pkey_export($key, $pk);
        $path = sys_get_temp_dir().'/forte-tls-'.getmypid().'.pem';
        file_put_contents($path, $cert.$pk);
        register_shutdown_function(fn () => @unlink($path));
    }

    return $path;
}

function tlsServer(Loop $loop): Server
{
    $routes = new RouteCollection;
    $routes->add('echo', Route::post('/echo', fn (RequestInterface $request) => new Response((string) $request->getBody(), 200)));

    return new Server(
        $loop,
        'tcp://127.0.0.1:0',
        ['local_cert' => selfSignedPem(), 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        new Router(new UrlMatcher($routes, new RequestContext)),
        100_000,
    );
}

it('delivers a request larger than one TLS read chunk', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    $loop = new Loop($driver);
    $server = tlsServer($loop);
    $body = str_repeat('a', 9000);

    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $client = stream_socket_client('tcp://'.$server->address(), $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $context);
    stream_set_blocking($client, false);
    for ($i = 0, $ok = 0; $i < 500 && $ok !== true; $i++) {
        $loop->tick(0.0);
        $ok = stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        usleep(1000);
    }
    expect($ok)->toBeTrue();

    fwrite($client, "POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: 9000\r\n\r\n".$body);
    $response = '';
    $deadline = microtime(true) + 3;
    while (! str_ends_with($response, $body) && microtime(true) < $deadline) {
        $loop->tick(0.01);
        $response .= (string) fread($client, 65536);
    }

    expect($response)->toEndWith($body);
    $server->stop();
})->with('tls drivers');

it('does not spin while a TCP client sits idle on the TLS port', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    $loop = new Loop($driver);
    $server = tlsServer($loop);
    $idle = stream_socket_client('tcp://'.$server->address(), $errno, $errstr, 1);
    $loop->tick(0.0); // accept it; it is now waiting for a ClientHello that never comes

    $start = hrtime(true);
    $loop->tick(0.2);

    expect((hrtime(true) - $start) / 1e9)->toBeGreaterThan(0.15);
    $server->stop();
})->with('tls drivers');
