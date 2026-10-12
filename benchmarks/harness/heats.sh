#!/bin/bash
# Every heat in BENCHMARKS.md. Layout: this directory at bench/harness, next to reverb-app/, forte-app/,
# uv.so and results/. Run it on the box under test.
set -u
cd "$(dirname "$0")"
RESULTS=../results
mkdir -p "$RESULTS/heat4-gc"

cell() { # cell SERVER LOOP CONNECTIONS LABEL [extra run.php args]
    local server=$1 loop=$2 n=$3 label=$4; shift 4
    php run.php --server="$server" --loop="$loop" --connections="$n" --label="$label" "$@" 2>&1 | grep -E "^$label|@|  [0-9]+s:"
}

# Heats 1-3: flat out, 1 s then 60 s. stream_select cells stop at the 1024 cap.
(ulimit -n 500;  for c in reverb:select forte:select reverb:uv forte:epoll; do cell ${c%%:*} ${c##*:} 450  heat1-cap500;  done)
(ulimit -n 1024; for c in reverb:select forte:select reverb:uv forte:epoll; do cell ${c%%:*} ${c##*:} 950  heat2-cap1024; done)
(ulimit -n 5000; for c in reverb:uv forte:epoll;                           do cell ${c%%:*} ${c##*:} 4900 heat3-cap5000; done)

# Heat 4: fixed rate, 30 s at 10 events/s then 30 s at 25 events/s; five interleaved reps, GC totals and pidstat.
pidstat -u -h -l 1 > "$RESULTS/heat4-pidstat.log" 2>&1 &
PIDSTAT=$!
(ulimit -n 1024
for rep in 1 2 3 4 5; do
    for c in reverb:select forte:select reverb:uv forte:epoll; do
        s=${c%%:*} l=${c##*:}
        GC_LOG="$(realpath "$RESULTS")/heat4-gc/rep$rep-$s-$l.json" SERVER_PHP_FLAGS="-d auto_prepend_file=$PWD/gcstat.php" \
            cell "$s" "$l" 950 "heat4-rep$rep" --stages=3:30:10,4:30:25
    done
done)
kill $PIDSTAT
python3 analyze-reps.py "$RESULTS"
