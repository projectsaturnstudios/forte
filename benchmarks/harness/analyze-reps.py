#!/usr/bin/env python3
"""Heat 4 reps: per cell and rate, p50/p99 across reps; every stalled event with what else was on the CPU then."""
import glob
import json
import os
import re
import statistics
import sys
import time
from collections import defaultdict

RESULTS = os.path.expanduser(sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'results'))
STALL_MS = 50
BENCH = ('run.php', 'client.php', 'publish.php', 'artisan reverb:start', 'artisan forte:start', 'pidstat')

# pidstat -h -l: "# Time UID PID %usr %system %guest %wait %CPU CPU Command"; Time is HH:MM:SS; run the box on UTC.
# Optional: heats.sh records it during Heat 4; without it every stall reads "(no pidstat log)".
busy = defaultdict(list)
pidstat = f'{RESULTS}/heat4-pidstat.log'
for line in open(pidstat, errors='replace') if os.path.exists(pidstat) else []:
    parts = line.split(None, 9)
    if len(parts) < 10 or parts[0].count(':') != 2 or not parts[1].isdigit():
        continue
    cpu, command = float(parts[7]), parts[9].strip()
    if cpu >= 5 and not any(b in command for b in BENCH):
        busy[parts[0]].append(f'{cpu:.0f}% {command[:70]}')

cells = defaultdict(lambda: defaultdict(list))
stalls = []
for path in sorted(glob.glob(f'{RESULTS}/heat4-rep*-*-950.json')):
    run = json.load(open(path))
    rep = re.search(r'rep\d+', os.path.basename(path)).group()
    cell = f"{run['server']}/{run['loop']}"
    gc_path = f"{RESULTS}/heat4-gc/{rep}-{run['server']}-{run['loop']}.json"
    gc = json.load(open(gc_path)) if os.path.exists(gc_path) else {}
    cells[cell]['gc_runs'].append(gc.get('runs'))
    cells[cell]['gc_ms'].append(round(gc.get('collector_time', 0) * 1000, 1))
    for stage in run['stages'].values():
        rate = stage['rate']
        cells[cell][f'p50@{rate:g}'].append(stage['p50_ms'])
        cells[cell][f'p99@{rate:g}'].append(stage['p99_ms'])
        cells[cell][f'cpu@{rate:g}'].append(stage['server_cpu_seconds'])
        events = stage.get('per_event_ms') or []
        events = dict(enumerate(events)) if isinstance(events, list) else {int(k): v for k, v in events.items()}
        slow = {n: v[1] for n, v in events.items() if v[1] > STALL_MS}
        cells[cell][f'stalled@{rate:g}'].append(len(slow))
        for n, worst in sorted(slow.items()):
            at = int(stage['publish']['start'] + n / rate)
            others = sorted({o for t in (at - 1, at, at + 1) for o in busy.get(time.strftime('%H:%M:%S', time.gmtime(t)), [])})
            stalls.append((cell, rep, rate, n, worst, at, others))

def spread(values):
    values = [v for v in values if v is not None]
    return f'{statistics.median(values):>7.2f} [{min(values):.2f}-{max(values):.2f}]' if values else '-'

keys = ['p50@10', 'p99@10', 'p50@25', 'p99@25']
print(f"{'cell':<14}" + ''.join(f'{k:>24}' for k in keys) + f"{'stalled@10':>14}{'stalled@25':>14}{'cpu@25':>10}{'gc runs':>10}{'gc ms':>12}")
for cell in ('reverb/select', 'forte/select', 'reverb/uv', 'forte/epoll'):
    c = cells[cell]
    print(f'{cell:<14}' + ''.join(f'{spread(c[k]):>24}' for k in keys)
          + f"{str(c['stalled@10']):>14}{str(c['stalled@25']):>14}{statistics.median(c['cpu@25']):>10.2f}"
          + f"{str(c['gc_runs']):>10}{str(c['gc_ms']):>12}")

print(f'\nstalled events (worst delivery > {STALL_MS} ms), with non-bench processes >= 5% CPU within a second:')
for cell, rep, rate, n, worst, at, others in stalls:
    print(f'  {cell:<14} {rep} {rate:g}/s event {n:<4} worst {worst:7.1f} ms at {time.strftime('%H:%M:%S', time.gmtime(at))} UTC  {"; ".join(others) or ("(nothing else busy)" if busy else "(no pidstat log)")}')
