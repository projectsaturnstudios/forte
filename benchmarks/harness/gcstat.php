<?php
// Bench instrument: on shutdown, write this process's garbage-collector totals next to the run's results.
if (($log = getenv("GC_LOG")) !== false && PHP_SAPI === "cli" && str_contains(implode(" ", $argv ?? []), "start")) {
    register_shutdown_function(function () use ($log) {
        file_put_contents($log, json_encode(gc_status() + ["peak" => memory_get_peak_usage(true)]));
    });
}
