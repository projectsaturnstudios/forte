<?php

// php run.php --server=reverb|forte --loop=select|uv|epoll --connections=N --label=cap500
//     [--per-client=450] [--concurrency=16] [--port=9090] [--stages=STAGE:SECONDS:RATE,...]
// One cell: start the server, subscribe N clients, then publish in stages, each followed by a drain. The default
// stages are flat out (stage 1: one second, stage 2: sixty seconds); RATE > 0 publishes at a fixed rate instead.
// Writes results/LABEL-SERVER-LOOP-N.json. SERVER_PHP_FLAGS adds php flags to the server process only.

use Bench\LatencyReservoir;

require __DIR__.'/bootstrap.php';

$o = getopt('', ['server:', 'loop:', 'connections:', 'label:', 'per-client::', 'concurrency::', 'port::', 'stages::']);
$server = $o['server'];
$loopName = $o['loop'];
$n = (int) $o['connections'];
$label = $o['label'];
$perClient = (int) ($o['per-client'] ?? 450);
$concurrency = (int) ($o['concurrency'] ?? 16);
$port = (int) ($o['port'] ?? 9090);
$root = dirname(__DIR__);
$out = "{$root}/results/{$label}-{$server}-{$loopName}-{$n}";
@mkdir($out, 0777, true);
// A rerun of the same label must not read the previous run's progress or stats files.
array_map('unlink', glob("{$out}/*") ?: []);

$result = [
    'label' => $label,
    'server' => $server,
    'loop' => $loopName,
    'connections' => $n,
    'nofile' => (int) trim((string) shell_exec('ulimit -n')),
    'php' => PHP_VERSION,
    'date' => gmdate('c'),
];

$php = [PHP_BINARY, '-d', 'memory_limit=-1'];
// Diagnostics only (e.g. a profiler): extra flags for the server process, never the clients or publisher.
$serverExtra = array_values(array_filter(explode(' ', (string) getenv('SERVER_PHP_FLAGS'))));

if ($server === 'reverb') {
    $flags = $loopName === 'uv' ? [...$php, '-d', "extension={$root}/uv.so"] : $php;
    $cwd = "{$root}/reverb-app";
    $result['react_loop'] = trim((string) shell_exec('cd '.escapeshellarg($cwd).' && '.implode(' ', array_map('escapeshellarg', [...$flags, '-r', 'require "vendor/autoload.php"; echo get_class(React\EventLoop\Loop::get());']))));
    $want = $loopName === 'uv' ? 'React\EventLoop\ExtUvLoop' : 'React\EventLoop\StreamSelectLoop';

    if ($result['react_loop'] !== $want) {
        exit("Reverb would run on {$result['react_loop']}, not {$want}\n");
    }

    $command = [...$flags, ...$serverExtra, 'artisan', 'reverb:start', '--host=127.0.0.1', "--port={$port}"];
} else {
    $cwd = "{$root}/forte-app";
    $command = [...$php, ...$serverExtra, 'artisan', 'forte:start', '--host=127.0.0.1', "--port={$port}", "--loop={$loopName}"];
}

function proc(int $pid, string $field): int
{
    preg_match('/^'.$field.':\s+(\d+)/m', (string) @file_get_contents("/proc/{$pid}/status"), $m);

    return (int) ($m[1] ?? 0);
}

/** User + system CPU seconds the process has used. */
function cpuSeconds(int $pid): float
{
    $raw = (string) @file_get_contents("/proc/{$pid}/stat");
    $stat = explode(' ', substr($raw, strrpos($raw, ')') + 2));

    return ((int) $stat[11] + (int) $stat[12]) / 100;
}

function waitForPort(int $port, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if ($s = @fsockopen('127.0.0.1', $port, $e, $m, 0.2)) {
            fclose($s);

            return true;
        }

        usleep(100_000);
    }

    return false;
}

if (waitForPort($port, 0.5)) {
    exit("Port {$port} is already in use\n");
}

$say = function (string $line) use ($label, $server, $loopName, $n) {
    echo gmdate('H:i:s')." [{$label} {$server}/{$loopName} n={$n}] {$line}\n";
};

$say('starting server');
$serverProc = proc_open($command, [1 => ['file', "{$out}/server.log", 'w'], 2 => ['file', "{$out}/server.log", 'a']], $pipes, $cwd);
$serverPid = proc_get_status($serverProc)['pid'];

$stopAll = function () use (&$clients, $serverProc) {
    foreach ($clients ?? [] as [$proc]) {
        proc_terminate($proc, SIGTERM);
        proc_close($proc);
    }
    proc_terminate($serverProc, SIGTERM);
    proc_close($serverProc);
};

if (! waitForPort($port, 30)) {
    $stopAll();
    exit("Server did not open port {$port}; see {$out}/server.log\n");
}

usleep(300_000);
$result['server_start_line'] = trim((string) strtok((string) file_get_contents("{$out}/server.log"), "\n"));
$result['rss_started_kb'] = proc($serverPid, 'VmRSS');
$say($result['server_start_line']);
$say("opening {$n} client connections");

// Clients: at most --per-client connections per process (each process lives under the same nofile limit).
$clients = [];
$procCount = (int) ceil($n / $perClient);
for ($i = 0; $i < $procCount; $i++) {
    $count = min($perClient, $n - $i * $perClient);
    $stats = "{$out}/client-{$i}.json";
    $clients[$i] = [proc_open([...$php, __DIR__.'/client.php', (string) $count, (string) $port, 'bench-key', '127.0.0.'.(2 + $i), $stats], [1 => ['file', "{$out}/client-{$i}.log", 'w'], 2 => ['file', "{$out}/client-{$i}.log", 'a']], $p), $stats];
}

$progress = function () use (&$clients): array {
    $sum = ['subscribed' => 0, 'failed' => 0, 'dropped' => 0, 'pings' => 0, 'stages' => []];

    foreach ($clients as [, $stats]) {
        $p = json_decode((string) @file_get_contents("{$stats}.progress"), true) ?: [];

        foreach (['subscribed', 'failed', 'dropped', 'pings'] as $k) {
            $sum[$k] += $p[$k] ?? 0;
        }

        foreach ($p['stages'] ?? [] as $stage => $s) {
            $sum['stages'][$stage]['seen'] = ($sum['stages'][$stage]['seen'] ?? 0) + $s['seen'];
            $sum['stages'][$stage]['last'] = max($sum['stages'][$stage]['last'] ?? 0, $s['last']);
        }
    }

    return $sum;
};

$connectStarted = microtime(true);
do {
    usleep(250_000);
    $now = $progress();
} while ($now['subscribed'] + $now['failed'] < $n && microtime(true) - $connectStarted < 120);

$result['subscribed'] = $now['subscribed'];
$result['connect_failed'] = $now['failed'];
$result['connect_seconds'] = round(microtime(true) - $connectStarted, 2);
$say("subscribed {$result['subscribed']}, failed {$result['connect_failed']}, in {$result['connect_seconds']}s; idling 5s");

sleep(5);
$result['rss_idle_kb'] = proc($serverPid, 'VmRSS');
$result['rss_per_connection_kb'] = $result['subscribed'] > 0 ? round(($result['rss_idle_kb'] - $result['rss_started_kb']) / $result['subscribed'], 2) : null;

// --stages=STAGE:SECONDS:RATE,... (RATE 0 = flat out). Default: the one-second and sixty-second flat-out stages.
$stages = [];
foreach (explode(',', $o['stages'] ?? '1:1:0,2:60:0') as $spec) {
    [$id, $secs, $r] = array_map('floatval', explode(':', $spec));
    $stages[(int) $id] = ['seconds' => (int) $secs, 'rate' => $r];
}
$result['stages'] = [];

foreach ($stages as $stage => ['seconds' => $seconds, 'rate' => $rate]) {
    $say($rate > 0 ? "stage {$stage}: publishing {$rate} events/s for {$seconds}s" : "stage {$stage}: publishing flat out for {$seconds}s");
    $cpuBefore = cpuSeconds($serverPid);
    $peak = proc($serverPid, 'VmRSS');
    $publisher = proc_open([...$php, __DIR__.'/publish.php', (string) $port, 'bench', 'bench-key', 'bench-secret', (string) $stage, (string) $seconds, (string) $concurrency, (string) $rate], [1 => ['pipe', 'w'], 2 => ['file', "{$out}/publish-{$stage}.log", 'w']], $pp);

    while (proc_get_status($publisher)['running']) {
        $peak = max($peak, proc($serverPid, 'VmRSS'));
        usleep(250_000);
    }

    $report = json_decode((string) stream_get_contents($pp[1]), true) ?? [];
    proc_close($publisher);

    $say(sprintf('stage %d: %d events accepted (%d errors); draining', $stage, $report['accepted'] ?? 0, $report['errors'] ?? 0));

    // Drain: wait until every accepted event has reached every subscriber, or nothing new lands for 5 s.
    $expected = ($report['accepted'] ?? 0) * $result['subscribed'];
    $seen = -1;
    $quietSince = microtime(true);
    $drainStarted = microtime(true);
    while (true) {
        $peak = max($peak, proc($serverPid, 'VmRSS'));
        $now = $progress();
        $current = $now['stages'][$stage]['seen'] ?? 0;

        if ($current !== $seen) {
            $seen = $current;
            $quietSince = microtime(true);
        }

        if ($current >= $expected || microtime(true) - $quietSince > 5 || microtime(true) - $drainStarted > 300) {
            break;
        }

        usleep(250_000);
    }

    $result['stages'][$stage] = [
        'publish_seconds' => $seconds,
        'rate' => $rate,
        'publish' => $report,
        'expected_deliveries' => $expected,
        'progress_seen' => $seen,
        'last_delivery' => $now['stages'][$stage]['last'] ?? null,
        'server_cpu_seconds' => round(cpuSeconds($serverPid) - $cpuBefore, 2),
        'server_rss_peak_kb' => $peak,
    ];
}

$result['dropped'] = $progress()['dropped'];
$result['pings_answered'] = $progress()['pings'];

foreach ($clients as [$proc]) {
    proc_terminate($proc, SIGTERM);
}
foreach ($clients as [$proc]) {
    proc_close($proc);
}
$clients = [];
proc_terminate($serverProc, SIGTERM);
proc_close($serverProc);

// Merge the clients' final stats: exact counts, last delivery, and latency samples.
$merged = [];
foreach (glob("{$out}/client-*.json") as $file) {
    $clientStats = json_decode((string) file_get_contents($file), true) ?: [];

    foreach ($clientStats['stages'] ?? [] as $stage => $s) {
        $merged[$stage]['seen'] = ($merged[$stage]['seen'] ?? 0) + $s['seen'];
        // Each client process subscribes its connections to every event: unique ids seen x its connections is what
        // it should have received; anything past that is the same event delivered twice.
        $merged[$stage]['unique'] = max($merged[$stage]['unique'] ?? 0, $s['unique'] ?? 0);
        $merged[$stage]['duplicates'] = ($merged[$stage]['duplicates'] ?? 0) + max(0, $s['seen'] - ($s['unique'] ?? 0) * ($clientStats['subscribed'] ?? 0));
        $merged[$stage]['last'] = max($merged[$stage]['last'] ?? 0, $s['last']);
        $merged[$stage]['samples'] = array_merge($merged[$stage]['samples'] ?? [], $s['samples']);
        // Per event: the earliest and latest delivery across every client, i.e. how long its fan-out took.
        foreach ($s['spread'] ?? [] as $id => [$low, $high]) {
            $known = $merged[$stage]['spread'][$id] ?? [$low, $high];
            $merged[$stage]['spread'][$id] = [min($known[0], $low), max($known[1], $high)];
        }
    }
}

foreach ($result['stages'] as $stage => &$row) {
    $m = $merged[$stage] ?? ['seen' => 0, 'last' => null, 'samples' => []];
    $start = $row['publish']['start'] ?? null;
    $end = $row['publish']['last_response'] ?? null;
    $row['delivered'] = $m['seen'];
    $row['unique_events_delivered'] = $m['unique'] ?? null;
    $row['duplicate_deliveries'] = $m['duplicates'] ?? null;
    $row['delivered_ratio'] = $row['expected_deliveries'] > 0 ? round($m['seen'] / $row['expected_deliveries'], 4) : null;
    $row['events_accepted'] = $row['publish']['accepted'] ?? 0;
    $row['events_per_second'] = round($row['publish']['events_per_second'] ?? 0, 1);
    $row['delivery_window_seconds'] = ($start && $m['last']) ? round($m['last'] - $start, 3) : null;
    $row['deliveries_per_second'] = $row['delivery_window_seconds'] ? round($m['seen'] / $row['delivery_window_seconds']) : null;
    $row['drain_ms'] = ($end && $m['last']) ? round(($m['last'] - $end) * 1000, 1) : null;
    foreach ([50, 99] as $p) {
        $v = LatencyReservoir::percentile($m['samples'] ?? [], $p);
        $row["p{$p}_ms"] = $v === null ? null : round($v, 2);
    }
    $row['max_ms'] = ($m['samples'] ?? []) ? round(max($m['samples']), 2) : null;
    $m['spread'] ??= [];
    ksort($m['spread']);
    $row['per_event_ms'] = array_map(fn ($pair) => [round($pair[0], 2), round($pair[1], 2)], $m['spread'] ?? []);
}
unset($row);

file_put_contents("{$out}.json", json_encode($result, JSON_PRETTY_PRINT));

$summary = fn (array $s) => sprintf('events %d (%.0f/s)  delivered %d/%d (%.1f%%)  %s deliveries/s  p50 %s ms  p99 %s ms  drain %s ms  cpu %.2fs  rss peak %d MB',
    $s['events_accepted'], $s['events_per_second'], $s['delivered'], $s['expected_deliveries'], 100 * ($s['delivered_ratio'] ?? 0),
    number_format((float) $s['deliveries_per_second']), $s['p50_ms'] ?? '-', $s['p99_ms'] ?? '-', $s['drain_ms'] ?? '-', $s['server_cpu_seconds'], $s['server_rss_peak_kb'] / 1024);

echo "{$label} {$server}/{$loopName} n={$n}: subscribed {$result['subscribed']} failed {$result['connect_failed']} in {$result['connect_seconds']}s, idle rss {$result['rss_idle_kb']} KB ({$result['rss_per_connection_kb']} KB/conn), dropped {$result['dropped']}\n";
foreach ($result['stages'] as $stage => $s) {
    echo '  '.($s['rate'] > 0 ? "{$s['publish_seconds']}s @ {$s['rate']}/s" : "{$s['publish_seconds']}s").': '.$summary($s)."\n";
}
