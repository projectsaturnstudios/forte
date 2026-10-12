<?php

// php publish.php PORT APP_ID KEY SECRET STAGE SECONDS CONCURRENCY [RATE]
// RATE 0 (default): keeps CONCURRENCY signed POST /apps/{id}/events requests in flight for SECONDS, as fast
// as the server answers. RATE > 0: starts RATE requests per second on a fixed clock instead (at most
// CONCURRENCY in flight; a tick that finds them all busy is counted as skipped). Each request is a `bench`
// event on channel `bench` carrying its send time. Prints a JSON report.

use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;

require __DIR__.'/bootstrap.php';

[, $port, $appId, $key, $secret, $stage, $seconds, $concurrency] = $argv;
$rate = (float) ($argv[8] ?? 0);

$loop = Loop::create('epoll');
$path = "/apps/{$appId}/events";
$start = microtime(true);
$stopAt = $start + (float) $seconds;
$report = ['stage' => (int) $stage, 'rate' => $rate, 'accepted' => 0, 'errors' => 0, 'skipped' => 0, 'statuses' => [], 'start' => $start, 'last_response' => $start];
$inflight = 0;
$started = 0;

$request = function (int $n) use ($path, $key, $secret, $stage, $port): string {
    // n: this request's sequence number, so clients can count unique events as well as deliveries.
    $body = json_encode(['name' => 'bench', 'channels' => ['bench'], 'data' => json_encode(['t' => microtime(true), 'g' => (int) $stage, 'n' => $n])]);
    $params = ['auth_key' => $key, 'auth_timestamp' => (string) time(), 'auth_version' => '1.0', 'body_md5' => md5($body)];
    ksort($params);
    $query = http_build_query($params);
    $signature = hash_hmac('sha256', "POST\n{$path}\n{$query}", $secret);

    return "POST {$path}?{$query}&auth_signature={$signature} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\n"
        ."Content-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}";
};

// One request on its own socket. Its buffers live in this call's scope, never shared with another request.
$send = function ($stream, int $n) use (&$launch, &$inflight, &$report, $loop, $request): void {
    $inflight++;
    $out = $request($n);
    $in = '';

    $finish = function (bool $ok, string $status) use ($stream, $loop, &$inflight, &$report, &$launch) {
        $loop->cancelReadable($stream);
        $loop->cancelWritable($stream);
        @fclose($stream);
        $inflight--;
        $ok ? $report['accepted']++ : $report['errors']++;
        $report['statuses'][$status] = ($report['statuses'][$status] ?? 0) + 1;
        $report['last_response'] = microtime(true);
        $launch();
    };

    $loop->onWritable($stream, function () use ($stream, $loop, &$out, $finish) {
        $written = @fwrite($stream, $out);

        if ($written === false) {
            $finish(false, 'write-failed');

            return;
        }

        $out = (string) substr($out, $written);

        if ($out === '') {
            $loop->cancelWritable($stream);
        }
    });

    $loop->onReadable($stream, function () use ($stream, &$in, $finish) {
        $data = @fread($stream, 65536);

        if ($data === false || ($data === '' && feof($stream))) {
            $status = (string) substr($in, 9, 3);
            $finish($status === '200', $status === '' ? 'no-response' : $status);

            return;
        }

        $in .= $data;
    });
};

// Open one request; false if the socket could not be created.
$openOne = function () use (&$started, &$report, $port, $send): bool {
    // 32 source addresses, so closed connections in TIME_WAIT never exhaust one ephemeral range.
    $n = $started++;
    $source = '127.0.1.'.(1 + $n % 32);
    $stream = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, stream_context_create(['socket' => ['bindto' => "{$source}:0"]]));

    if ($stream === false) {
        $report['errors']++;

        return false;
    }

    stream_set_blocking($stream, false);
    $send($stream, $n);

    return true;
};

// Flat out: refill to CONCURRENCY whenever a request finishes. At a fixed rate the clock below starts requests.
$launch = function () use (&$launch, &$inflight, $loop, $concurrency, $stopAt, $rate, $openOne) {
    while ($rate <= 0 && $inflight < (int) $concurrency && microtime(true) < $stopAt) {
        if (! $openOne()) {
            $loop->addTimer(0.01, $launch);

            return;
        }
    }
};

if ($rate > 0) {
    $loop->addPeriodicTimer(1 / $rate, function () use (&$inflight, &$report, $concurrency, $stopAt, $openOne) {
        if (microtime(true) >= $stopAt) {
            return;
        }

        $inflight < (int) $concurrency ? $openOne() : $report['skipped']++;
    });
} else {
    $launch();
}

$loop->addPeriodicTimer(0.05, function () use ($loop, &$inflight, $stopAt) {
    if (microtime(true) >= $stopAt && $inflight === 0) {
        $loop->stop();
    }
});

$loop->run();

$report['seconds'] = $report['last_response'] - $report['start'];
$report['events_per_second'] = $report['seconds'] > 0 ? $report['accepted'] / $report['seconds'] : 0;

echo json_encode($report), PHP_EOL;
