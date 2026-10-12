<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Factory;
use ProjectSaturnStudios\Forte\Servers\Forte\Http\Server;

it('can create a server', function () {
    $server = Factory::make('127.0.0.1', '0');

    expect($server)->toBeInstanceOf(Server::class)->and($server->isSecure())->toBeFalse();

    $server->stop();
});

it('can create a server with the given host and port', function () {
    $server = Factory::make('127.0.0.1', '8001');

    expect($server->address())->toBe('127.0.0.1:8001');

    $server->stop();
});

it('can create a tls server using a user provided certificate', function () {
    $this->app->config->set('forte.servers.forte.options.tls.local_cert', '/path/to/cert.pem');
    $this->app->config->set('forte.servers.forte.options.tls.verify_peer', false);
    $server = Factory::make('127.0.0.1', '0', options: $this->app->config->get('forte.servers.forte.options'));

    $context = (new ReflectionProperty($server, 'tlsContext'))->getValue($server);

    expect($server->isSecure())->toBeTrue()
        ->and($context['local_cert'])->toBe('/path/to/cert.pem')
        ->and($context['verify_peer'])->toBeFalse();

    $server->stop();
});

it('can create a server without tls when context values are null', function () {
    $this->app->config->set('forte.servers.forte.options.tls.local_cert', null);
    $this->app->config->set('forte.servers.forte.options.tls.verify_peer', null);
    $server = Factory::make('127.0.0.1', '0', options: $this->app->config->get('forte.servers.forte.options'));

    expect($server->isSecure())->toBeFalse();

    $server->stop();
});

it('uses the loop it is given', function () {
    $loop = ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop::create('select');
    $server = Factory::make('127.0.0.1', '0', loop: $loop);

    expect($server->loop())->toBe($loop);

    $server->stop();
});
