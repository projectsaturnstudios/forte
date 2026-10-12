<?php

namespace Bench;

use ProjectSaturnStudios\Forte\Servers\Forte\Loop\LoopInterface;

/** N client connections, at most 64 handshakes in flight; counts and times bench events per stage. */
final class Client
{
    public int $subscribed = 0;

    public int $failed = 0;

    public int $dropped = 0;

    public int $pings = 0;

    private int $pending = 0;

    private int $queued = 0;

    /** @var list<ClientConnection> */
    private array $connections = [];

    /** @var array<int, array{seen: int, first: float, last: float, reservoir: LatencyReservoir, ids: array<int, true>, spread: array<int, array{float, float}>}> */
    private array $stages = [];

    public function __construct(private LoopInterface $loop, private int $port, private string $appKey, private string $bindIp) {}

    public function open(int $count): void
    {
        $this->queued += $count;
        $this->fill();
    }

    private function fill(): void
    {
        while ($this->queued > 0 && $this->pending < 64) {
            $this->queued--;
            $context = stream_context_create(['socket' => ['bindto' => "{$this->bindIp}:0"]]);
            $stream = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context);

            if ($stream === false) {
                $this->failed++;

                continue;
            }

            stream_set_blocking($stream, false);
            $this->pending++;
            $this->connections[] = new ClientConnection($stream, $this->loop, $this, $this->port, $this->appKey);
        }
    }

    public function subscribed(): void
    {
        $this->subscribed++;
        $this->pending--;
        $this->fill();
    }

    public function failed(): void
    {
        $this->failed++;
        $this->pending--;
        $this->fill();
    }

    public function dropped(): void
    {
        $this->dropped++;
        $this->subscribed--;
    }

    public function pinged(): void
    {
        $this->pings++;
    }

    public function event(int $stage, float $ms, float $now, int $n): void
    {
        $s = &$this->stages[$stage];
        $s ??= ['seen' => 0, 'first' => $now, 'last' => $now, 'reservoir' => new LatencyReservoir, 'ids' => [], 'spread' => []];
        $s['seen']++;
        $s['ids'][$n] = true;
        $spread = $s['spread'][$n] ?? [$ms, $ms];
        $s['spread'][$n] = [min($spread[0], $ms), max($spread[1], $ms)];
        $s['last'] = $now;
        $s['reservoir']->add($ms);
    }

    public function progress(): array
    {
        return [
            'subscribed' => $this->subscribed,
            'failed' => $this->failed,
            'dropped' => $this->dropped,
            'pings' => $this->pings,
            'stages' => array_map(fn ($s) => ['seen' => $s['seen'], 'unique' => count($s['ids']), 'first' => $s['first'], 'last' => $s['last']], $this->stages),
        ];
    }

    public function stats(): array
    {
        $progress = $this->progress();

        foreach ($this->stages as $stage => $s) {
            $progress['stages'][$stage]['samples'] = $s['reservoir']->samples();
            $progress['stages'][$stage]['spread'] = $s['spread'];
        }

        return $progress;
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
    }
}
