<?php

// php client.php COUNT PORT KEY BIND_IP STATS_FILE
// Opens COUNT subscribed clients. Writes STATS_FILE.progress every half second, STATS_FILE on SIGTERM.

use Bench\Client;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Loop;

require __DIR__.'/bootstrap.php';

[, $count, $port, $key, $bindIp, $statsFile] = $argv;

$loop = Loop::create('epoll');
$client = new Client($loop, (int) $port, $key, $bindIp);
$client->open((int) $count);

$loop->addPeriodicTimer(0.5, fn () => file_put_contents($statsFile.'.progress', json_encode($client->progress())));

pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn () => $loop->stop());

$loop->run();

file_put_contents($statsFile, json_encode($client->stats()));
$client->closeAll();
