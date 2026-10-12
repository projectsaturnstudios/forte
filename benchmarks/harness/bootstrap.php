<?php

// The harness borrows the Forte app's autoloader for its loop classes and ratchet/rfc6455. The same
// client and publisher drive both servers; only the server process differs between runs.
require dirname(__DIR__).'/forte-app/vendor/autoload.php';
require __DIR__.'/src/LatencyReservoir.php';
require __DIR__.'/src/ClientConnection.php';
require __DIR__.'/src/Client.php';
