<?php

namespace ProjectSaturnStudios\Forte\Tests;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Uri;
use ProjectSaturnStudios\Forte\Promise\Deferred;
use ProjectSaturnStudios\Forte\Promise\Promise;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A one-request HTTP/1.1 client on Forte's loop for the test suite. Like the browser it
 * replaces, it rejects a 4xx or 5xx answer with a ResponseException carrying the status.
 */
final class HttpClient
{
    /**
     * @param  array<string, string>  $headers
     * @return Promise<ResponseInterface>
     */
    public static function request(LoopInterface $loop, string $method, string $url, array $headers = [], string $body = ''): Promise
    {
        $uri = new Uri($url);
        $authority = $uri->getHost().':'.($uri->getPort() ?? 80);
        $target = ($uri->getPath() ?: '/').($uri->getQuery() !== '' ? "?{$uri->getQuery()}" : '');

        $headers += ['Host' => $authority, 'User-Agent' => 'Forte-Tests', 'Connection' => 'close'];

        if ($body !== '' || in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $headers['Content-Length'] = (string) strlen($body);
        }

        $request = "{$method} {$target} HTTP/1.1\r\n";

        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }

        $request .= "\r\n{$body}";

        $stream = @stream_socket_client("tcp://{$authority}", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

        if ($stream === false) {
            return Promise::reject(new RuntimeException("Connection to {$url} failed: {$errstr}"));
        }

        stream_set_blocking($stream, false);

        $deferred = new Deferred;
        $received = '';

        $finish = function (?callable $settle = null) use ($loop, $stream, $deferred, $url, &$received) {
            $loop->cancelReadable($stream);
            $loop->cancelWritable($stream);
            @fclose($stream);

            if ($settle) {
                $settle();

                return;
            }

            if ($received === '') {
                $deferred->reject(new RuntimeException("Connection to {$url} closed without a response."));

                return;
            }

            $response = Message::parseResponse($received);

            $response->getStatusCode() >= 400
                ? $deferred->reject(new ResponseException($response))
                : $deferred->resolve($response);
        };

        $connected = false;

        $loop->onWritable($stream, function () use ($loop, $stream, $deferred, $url, $finish, &$request, &$connected) {
            if (! $connected && @stream_socket_get_name($stream, true) === false) {
                $finish(fn () => $deferred->reject(new RuntimeException("Connection to {$url} refused.")));

                return;
            }

            $connected = true;
            $written = @fwrite($stream, $request);

            if ($written === false) {
                $finish(fn () => $deferred->reject(new RuntimeException("Connection to {$url} lost while sending.")));

                return;
            }

            $request = (string) substr($request, $written);

            if ($request === '') {
                $loop->cancelWritable($stream);
            }
        });

        $loop->onReadable($stream, function () use ($stream, $finish, &$received) {
            $data = @fread($stream, 65536);

            if ($data === false || ($data === '' && feof($stream))) {
                $finish();

                return;
            }

            $received .= $data;
        });

        return $deferred->promise();
    }
}
