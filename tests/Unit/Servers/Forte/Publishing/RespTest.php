<?php

use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\Resp;
use ProjectSaturnStudios\Forte\Servers\Forte\Publishing\RespError;

it('encodes a command as a RESP array of bulk strings', function () {
    expect(Resp::encode(['PUBLISH', 'forte', '{"a":1}']))
        ->toBe("*3\r\n$7\r\nPUBLISH\r\n$5\r\nforte\r\n$7\r\n{\"a\":1}\r\n");
});

it('decodes simple strings, integers, bulk strings, nulls, errors and arrays', function () {
    $resp = new Resp;
    $resp->feed("+OK\r\n:42\r\n$5\r\nhello\r\n$-1\r\n-NOAUTH Authentication required.\r\n*3\r\n$7\r\nmessage\r\n$5\r\nforte\r\n$2\r\nhi\r\n");

    expect($resp->next())->toBe('OK');
    expect($resp->next())->toBe(42);
    expect($resp->next())->toBe('hello');
    expect($resp->next())->toBeNull();
    $error = $resp->next();
    expect($error)->toBeInstanceOf(RespError::class)->and($error->message)->toBe('NOAUTH Authentication required.');
    expect($resp->next())->toBe(['message', 'forte', 'hi']);
    expect($resp->next())->toBe(Resp::INCOMPLETE);
});

it('carries a partial frame across feeds', function () {
    $resp = new Resp;
    $full = "*3\r\n$7\r\nmessage\r\n$5\r\nforte\r\n$11\r\nhello world\r\n";

    for ($i = 1; $i < strlen($full); $i++) {
        $resp->feed($full[$i - 1]);
        expect($resp->next())->toBe(Resp::INCOMPLETE);
    }
    $resp->feed($full[strlen($full) - 1]);

    expect($resp->next())->toBe(['message', 'forte', 'hello world']);
});

it('decodes a bulk string containing CRLF by length, not by delimiter', function () {
    $resp = new Resp;
    $resp->feed("$4\r\na\r\nb\r\n");

    expect($resp->next())->toBe("a\r\nb");
});

it('decodes nested arrays and null arrays', function () {
    $resp = new Resp;
    $resp->feed("*2\r\n*1\r\n:1\r\n*-1\r\n");

    expect($resp->next())->toBe([[1], null]);
});
