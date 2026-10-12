<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Loop\DriverInterface;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\EpollDriver;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\KqueueDriver;
use ProjectSaturnStudios\Forte\Servers\Forte\Loop\Drivers\SelectDriver;

dataset('drivers', [
    'select' => [fn () => new SelectDriver],
    'epoll' => [fn () => function_exists('epoll_create1') ? new EpollDriver : null],
    'kqueue' => [fn () => function_exists('kqueue') ? new KqueueDriver : null],
]);

function readyMap(iterable $ready): array
{
    $map = [];
    foreach ($ready as [$fd, $mask]) {
        $map[$fd] = $mask;
    }

    return $map;
}

it('reports a stream readable after the peer writes', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::READ);

    expect(readyMap($driver->wait(0.0)))->toBe([]);

    fwrite($b, 'x');

    expect(readyMap($driver->wait(1.0))[(int) $a] & DriverInterface::READ)->toBe(DriverInterface::READ);
})->with('drivers');

it('reports an idle socket writable and stops after unwatch', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::WRITE);

    expect(readyMap($driver->wait(0.0))[(int) $a] & DriverInterface::WRITE)->toBe(DriverInterface::WRITE);

    $driver->unwatch((int) $a);

    expect(readyMap($driver->wait(0.0)))->toBe([]);
})->with('drivers');

it('switches masks with modify', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::WRITE);
    $driver->modify((int) $a, DriverInterface::READ);

    expect(readyMap($driver->wait(0.0)))->toBe([]);

    fwrite($b, 'x');

    expect(readyMap($driver->wait(1.0))[(int) $a] & DriverInterface::READ)->toBe(DriverInterface::READ);
})->with('drivers');

it('reports a peer close as readable', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::READ);
    fclose($b);

    expect(readyMap($driver->wait(1.0))[(int) $a] & DriverInterface::READ)->toBe(DriverInterface::READ);
    expect(fread($a, 1))->toBe('');
    expect(feof($a))->toBeTrue();
})->with('drivers');

it('times out and returns nothing', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::READ);
    $start = hrtime(true);

    expect(readyMap($driver->wait(0.02)))->toBe([]);
    expect((hrtime(true) - $start) / 1e9)->toBeGreaterThanOrEqual(0.02);
})->with('drivers');

it('select refuses more descriptors than FD_SETSIZE', function () {
    $limit = function_exists('posix_getrlimit') ? (posix_getrlimit()['soft openfiles'] ?? 0) : 0;
    if ($limit !== 'unlimited' && (int) $limit < 2 * SelectDriver::MAX_DESCRIPTORS + 64) {
        $this->markTestSkipped("needs ulimit -n >= ".(2 * SelectDriver::MAX_DESCRIPTORS + 64).", have {$limit}");
    }
    $driver = new SelectDriver;
    $pairs = [];
    for ($i = 0; $i < SelectDriver::MAX_DESCRIPTORS; $i++) {
        $pairs[] = $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $driver->watch((int) $pair[0], $pair[0], DriverInterface::READ);
    }
    $extra = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    expect(fn () => $driver->watch((int) $extra[0], $extra[0], DriverInterface::READ))
        ->toThrow(RuntimeException::class, 'cannot watch more than 1024 descriptors');
});

it('select counts a descriptor watched both ways once against the ceiling', function () {
    $driver = new SelectDriver(maxDescriptors: 2);
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    [$c, $d] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    $driver->watch((int) $a, $a, DriverInterface::READ | DriverInterface::WRITE);
    $driver->watch((int) $c, $c, DriverInterface::READ);

    expect(true)->toBeTrue();
});
