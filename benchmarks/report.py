#!/usr/bin/env python3
"""Writes benchmarks/report.html: one self-contained page with charts and inputs over every heat.

    python3 benchmarks/report.py

Reads results/ only, with the formulas charts.py prints BENCHMARKS.md's tables from. Chart.js
(vendor/chart.umd.min.js, MIT) and the data are inlined, so the page opens offline. Standard library only.
"""
import glob
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))
RESULTS = os.path.join(HERE, 'results')

CELLS = [('reverb', 'select'), ('forte', 'select'), ('reverb', 'uv'), ('forte', 'epoll')]
NAMES = {
    'reverb:select': 'Reverb · stream_select',
    'forte:select': 'Forte · stream_select',
    'reverb:uv': 'Reverb · ext-uv',
    'forte:epoll': 'Forte · ext-epoll',
}
# Forte against Reverb on the same tier: no extension, and the extension each one recommends.
RIVAL = {'forte:select': 'reverb:select', 'forte:epoll': 'reverb:uv'}
HEATS = [(1, 500, 450), (2, 1024, 950), (3, 5000, 4900)]
# Slowest delivery per event, Heat 4: 2 ms bins up to 60 ms, then one bin for everything slower.
BIN_MS, BINS = 2, 30


def flat_out(heat, cap, n, server, loop):
    """Heats 1-3: stage 1 is the one-second test, stage 2 the sixty-second one."""
    path = os.path.join(RESULTS, f'heat{heat}-cap{cap}-{server}-{loop}-{n}.json')
    if not os.path.exists(path):
        return None
    run = json.load(open(path))
    one, sixty = run['stages']['1'], run['stages']['2']
    return {
        'connect_s': run['connect_seconds'],
        'kb_per_conn': run['rss_per_connection_kb'],
        'eps_1': one['events_per_second'], 'eps_60': sixty['events_per_second'],
        'events_60': sixty['events_accepted'], 'delivered_60': sixty['delivered'],
        'ratio_60': sixty['delivered_ratio'], 'dps_60': sixty['deliveries_per_second'],
        'drain_s': max(0.0, sixty['drain_ms'] / 1000), 'cpu_60': sixty['server_cpu_seconds'],
        'us_per_delivery': sixty['server_cpu_seconds'] / sixty['delivered'] * 1e6,
        'rss_peak_mb': sixty['server_rss_peak_kb'] / 1024,
        'p50_60': sixty['p50_ms'], 'p99_60': sixty['p99_ms'],
        'php': run['php'], 'date': run['date'],
    }


def fixed_rate(server, loop):
    """Heat 4: every rep's numbers per rate, and the slowest delivery of every event as a histogram."""
    out = {}
    for path in sorted(glob.glob(os.path.join(RESULTS, f'heat4-rep*-{server}-{loop}-950.json'))):
        rep = int(os.path.basename(path).split('-')[1][3:])
        for stage in json.load(open(path))['stages'].values():
            rate = int(stage['rate'])
            row = out.setdefault(str(rate), {'reps': [], 'hist': [0] * (BINS + 1), 'events': 0})
            worst = [w for _, w in stage['per_event_ms']]
            row['reps'].append({
                'rep': rep, 'p50': stage['p50_ms'], 'p99': stage['p99_ms'], 'max': stage['max_ms'],
                'cpu': stage['server_cpu_seconds'], 'stalled': sum(1 for w in worst if w > 50),
                'delivered': stage['delivered'], 'expected': stage['expected_deliveries'],
            })
            for w in worst:
                row['hist'][min(int(w // BIN_MS), BINS)] += 1
            row['events'] += len(worst)
    for row in out.values():
        row['reps'].sort(key=lambda r: r['rep'])
    return out


def gc_stats():
    gc = {}
    for path in glob.glob(os.path.join(RESULTS, 'heat4-gc', '*.json')):
        _, server, loop = os.path.basename(path)[:-5].split('-')
        g = json.load(open(path))
        gc.setdefault(f'{server}:{loop}', []).append({'runs': g['runs'], 'collector_ms': g['collector_time'] * 1000})
    return gc


def main():
    heats = []
    for heat, cap, n in HEATS:
        cells = {f'{s}:{l}': flat_out(heat, cap, n, s, l) for s, l in CELLS}
        heats.append({'heat': heat, 'nofile': cap, 'connections': n, 'cells': {k: v for k, v in cells.items() if v}})
    payload = {
        'heats': heats,
        'fixed': {f'{s}:{l}': fixed_rate(s, l) for s, l in CELLS},
        'gc': gc_stats(),
        'names': NAMES, 'order': list(NAMES), 'rival': RIVAL, 'bin_ms': BIN_MS, 'bins': BINS,
    }
    chart = open(os.path.join(HERE, 'vendor', 'chart.umd.min.js')).read()
    template = open(os.path.join(HERE, 'report.template.html')).read()
    data = json.dumps(payload, separators=(',', ':')).replace('</', '<\\/')
    out = os.path.join(HERE, 'report.html')
    open(out, 'w').write(template.replace('/*__CHARTJS__*/', chart).replace('/*__DATA__*/null', data))
    print(f'wrote {out}')


if __name__ == '__main__':
    main()
