<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Http;

use OverflowException;
use ProjectSaturnStudios\Forte\Loggers\Log;
use ProjectSaturnStudios\Forte\Servers\Forte\Concerns\ClosesConnections;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Server
{
    use ClosesConnections;

    /** @var resource */
    protected $listener;

    protected string $address;

    /**
     * @param  string  $uri  tcp://host:port; TLS is enabled per connection from $tlsContext, never from the scheme
     * @param  array<string, mixed>  $tlsContext  the ssl stream-context options; empty means plaintext
     */
    public function __construct(
        protected LoopInterface $loop,
        string $uri,
        protected array $tlsContext,
        protected Router $router,
        protected int $maxRequestSize,
        protected int $maxWriteBuffer = Connection::MAX_WRITE_BUFFER,
    ) {
        gc_disable();
        $this->loop->addPeriodicTimer(30, fn () => gc_collect_cycles());

        // backlog 511, as React's TcpServer listens for Reverb; PHP's default of 32 refuses a reconnect storm.
        $context = stream_context_create(['socket' => ['backlog' => 511]] + ($tlsContext === [] ? [] : ['ssl' => $tlsContext]));
        $listener = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if ($listener === false) {
            throw new RuntimeException("Could not listen on {$uri}: {$errstr} ({$errno})");
        }

        stream_set_blocking($listener, false);
        $this->listener = $listener;
        $this->address = stream_socket_get_name($listener, false);
        $this->loop->onReadable($listener, $this->accept(...));
    }

    public function start(): void
    {
        try {
            $this->loop->run();
        } catch (Throwable $e) {
            Log::error($e->getMessage());
        }
    }

    public function stop(): void
    {
        $this->loop->stop();

        if (! is_resource($this->listener)) {
            return;
        }

        $this->loop->cancelReadable($this->listener);
        fclose($this->listener);
    }

    public function isSecure(): bool
    {
        return $this->tlsContext !== [];
    }

    /** host:port the listener is bound to; with port 0 in the uri this is the kernel-assigned port. */
    public function address(): string
    {
        return $this->address;
    }

    public function loop(): LoopInterface
    {
        return $this->loop;
    }

    protected function accept(): void
    {
        while (($socket = @stream_socket_accept($this->listener, 0)) !== false) {
            stream_set_blocking($socket, false);

            try {
                $connection = new Connection($socket, $this->loop, $this->isSecure(), $this->maxWriteBuffer);
            } catch (RuntimeException $e) {
                // The loop cannot take another descriptor (stream_select's ceiling): refuse this one, keep serving.
                fclose($socket);
                Log::error($e->getMessage());

                continue;
            }

            $connection->onData(fn (string $data) => $this->handleRequest($data, $connection));
        }
    }

    protected function handleRequest(string $message, Connection $connection): void
    {
        if ($connection->isConnected()) {
            return;
        }

        if (($request = $this->createRequest($message, $connection)) === null) {
            return;
        }

        $connection->connect();

        try {
            $this->router->dispatch($request, $connection);
        } catch (HttpException $e) {
            $this->close($connection, $e->getStatusCode(), $e->getMessage());
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            $this->close($connection, 500, 'Internal server error.');
        }
    }

    protected function createRequest(string $message, Connection $connection): ?RequestInterface
    {
        try {
            $request = Request::from($message, $connection, $this->maxRequestSize);
        } catch (OverflowException $e) {
            $this->close($connection, 413, 'Payload too large.');
        } catch (Throwable $e) {
            $this->close($connection, 400, 'Bad request.');
        }

        return $request ?? null;
    }
}
