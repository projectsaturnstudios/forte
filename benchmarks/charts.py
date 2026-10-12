#!/usr/bin/env python3
"""Draws the benchmark charts from results/ and prints the tables BENCHMARKS.md carries.

    python3 benchmarks/charts.py

Writes forte-vs-reverb-{light,dark}.svg and tmb-{light,dark}.svg next to this file. Standard library only.
"""
import glob
import json
import os
import statistics
from html import escape

HERE = os.path.dirname(os.path.abspath(__file__))
RESULTS = os.path.join(HERE, 'results')

CELLS = [('reverb', 'select'), ('forte', 'select'), ('reverb', 'uv'), ('forte', 'epoll')]
NAMES = {
    ('reverb', 'select'): 'Reverb · stream_select',
    ('reverb', 'uv'): 'Reverb · ext-uv',
    ('forte', 'select'): 'Forte · stream_select',
    ('forte', 'epoll'): 'Forte · ext-epoll',
}
# Forte against Reverb on the same tier: no extension, and the extension each one recommends.
RIVAL = {('forte', 'select'): ('reverb', 'select'), ('forte', 'epoll'): ('reverb', 'uv')}
HEATS = [(1, 500, 450), (2, 1024, 950), (3, 5000, 4900)]

THEMES = {
    'light': {
        'bg': '#ffffff', 'panel': '#f6f8fa', 'border': '#d0d7de', 'grid': '#d8dee4', 'text': '#1f2328',
        'muted': '#59636e', 'better': '#1a7f37', 'worse': '#cf222e',
        ('reverb', 'select'): '#c3ccd6', ('reverb', 'uv'): '#6e7b8b',
        ('forte', 'select'): '#ffb77a', ('forte', 'epoll'): '#e8590c',
    },
    'dark': {
        'bg': '#0d1117', 'panel': '#161b22', 'border': '#30363d', 'grid': '#30363d', 'text': '#e6edf3',
        'muted': '#9198a1', 'better': '#3fb950', 'worse': '#f85149',
        ('reverb', 'select'): '#3d4653', ('reverb', 'uv'): '#8b98a9',
        ('forte', 'select'): '#a8471b', ('forte', 'epoll'): '#ff8c42',
    },
}
SANS = "-apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans', Helvetica, Arial, sans-serif"
SCRAWL = "'Comic Sans MS', 'Comic Neue', 'Chalkboard SE', 'Marker Felt', cursive"


def load(name):
    return json.load(open(os.path.join(RESULTS, name)))


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
        'date': run['date'],
    }


def fixed_rate(server, loop):
    """Heat 4: median and range across the five reps, per rate."""
    out = {}
    for path in sorted(glob.glob(os.path.join(RESULTS, f'heat4-rep*-{server}-{loop}-950.json'))):
        for stage in json.load(open(path))['stages'].values():
            rate = int(stage['rate'])
            row = out.setdefault(rate, {'p50': [], 'p99': [], 'cpu': [], 'stalled': 0, 'delivered': 0, 'expected': 0})
            row['p50'].append(stage['p50_ms'])
            row['p99'].append(stage['p99_ms'])
            row['cpu'].append(stage['server_cpu_seconds'])
            row['stalled'] += sum(1 for _, worst in stage['per_event_ms'] if worst > 50)
            row['delivered'] += stage['delivered']
            row['expected'] += stage['expected_deliveries']
    return out


def delta(forte, reverb, higher_is_better):
    change = (forte / reverb - 1) * 100
    better = change > 0 if higher_is_better else change < 0
    return f"{'+' if change > 0 else '−'}{abs(change):.0f}%", better


def fmt(v):
    return f'{v:,.0f}' if v >= 100 else f'{v:.1f}'


class Svg:
    def __init__(self, width, height, theme):
        self.w, self.h, self.t, self.parts = width, height, THEMES[theme], []

    def add(self, s):
        self.parts.append(s)

    def text(self, x, y, s, size=13, color='text', weight=400, anchor='start', family=SANS, extra=''):
        fill = self.t.get(color, color)
        self.add(f'<text x="{x:.1f}" y="{y:.1f}" font-family="{family}" font-size="{size}" font-weight="{weight}" '
                 f'fill="{fill}" text-anchor="{anchor}"{extra}>{escape(s)}</text>')

    def rect(self, x, y, w, h, fill, rx=0, stroke=None):
        s = f' stroke="{self.t.get(stroke, stroke)}"' if stroke else ''
        self.add(f'<rect x="{x:.1f}" y="{y:.1f}" width="{w:.1f}" height="{h:.1f}" rx="{rx}" fill="{self.t.get(fill, fill)}"{s}/>')

    def line(self, x1, y1, x2, y2, color='grid', width=1, extra=''):
        self.add(f'<line x1="{x1:.1f}" y1="{y1:.1f}" x2="{x2:.1f}" y2="{y2:.1f}" stroke="{self.t.get(color, color)}" stroke-width="{width}"{extra}/>')

    def write(self, path, title):
        head = (f'<svg xmlns="http://www.w3.org/2000/svg" width="{self.w}" height="{self.h}" viewBox="0 0 {self.w} {self.h}" '
                f'role="img" aria-label="{escape(title)}"><title>{escape(title)}</title>')
        open(path, 'w').write(head + f'<rect width="100%" height="100%" fill="{self.t["bg"]}"/>' + ''.join(self.parts) + '</svg>\n')


def panel(svg, x, y, w, h, title, subtitle, groups, higher_is_better, unit=''):
    """groups: [(label, sublabel, {cell: value})]. Bars are scaled within each group, from zero."""
    svg.rect(x, y, w, h, 'panel', rx=10, stroke='border')
    svg.text(x + 20, y + 32, title, size=17, weight=650)
    svg.text(x + 20, y + 52, subtitle, size=12.5, color='muted')
    top, base = y + 92, y + h - 50
    svg.line(x + 16, base, x + w - 16, base, color='border', width=1.2)
    group_w = (w - 32) / len(groups)
    for i, (label, sub, values) in enumerate(groups):
        cells = [c for c in CELLS if c in values]
        bw = min(34, (group_w - 24 - (len(cells) - 1) * 6) / len(cells))
        span = len(cells) * bw + (len(cells) - 1) * 6
        gx = x + 16 + i * group_w + (group_w - span) / 2
        peak = max(values.values())
        for j, cell in enumerate(cells):
            v = values[cell]
            bh = (base - top - 30) * v / peak
            bx = gx + j * (bw + 6)
            svg.rect(bx, base - bh, bw, bh, cell, rx=3)
            svg.text(bx + bw / 2, base - bh - 6, fmt(v) + unit, size=11.5, anchor='middle', weight=600)
            if cell in RIVAL and RIVAL[cell] in values:
                label_d, better = delta(v, values[RIVAL[cell]], higher_is_better)
                svg.text(bx + bw / 2, base - bh - 21, label_d, size=11.5, anchor='middle', weight=750,
                         color='better' if better else 'worse')
        svg.text(x + 16 + i * group_w + group_w / 2, base + 20, label, size=12.5, anchor='middle', weight=600)
        svg.text(x + 16 + i * group_w + group_w / 2, base + 36, sub, size=11.5, anchor='middle', color='muted')


def main_chart(theme, heats, h4):
    svg = Svg(1200, 920, theme)
    svg.text(32, 52, 'Forte vs Reverb', size=32, weight=750)
    svg.text(32, 80, 'One shared vCPU (AWS Graviton3) running staging sites alongside · PHP 8.4.23 · Laravel 13.35 · '
             'Reverb 1.12.0 · Forte 0.10.2', size=13.5, color='muted')
    lx = 32
    for cell in CELLS:
        svg.rect(lx, 98, 14, 14, cell, rx=3)
        svg.text(lx + 20, 110, NAMES[cell], size=13)
        lx += 34 + len(NAMES[cell]) * 7.4

    def groups(metric):
        return [(f'{n:,} connections', f'nofile {cap}', {c: heats[(h, c)][metric] for c in CELLS if heats.get((h, c))})
                for h, cap, n in HEATS]

    pw, ph = 556, 360
    panel(svg, 32, 132, pw, ph, 'Broadcast throughput', 'Events per second, 60 s flat out; every event to every client · higher is better',
          groups('eps_60'), True)
    panel(svg, 612, 132, pw, ph, 'Server CPU per delivered message', 'Microseconds of server CPU per frame delivered · lower is better',
          groups('us_per_delivery'), False, ' µs')
    panel(svg, 32, 512, pw, ph, 'Memory per idle connection', 'Server RSS growth per subscribed client · lower is better',
          groups('kb_per_conn'), False, ' KB')
    latency = [(f'p{p} @ {r}/s', f'median of 5 runs', {c: statistics.median(h4[c][r][f'p{p}']) for c in CELLS})
               for r in (10, 25) for p in (50, 99)]
    panel(svg, 612, 512, pw, ph, 'Delivery latency at a fixed rate', '950 clients, publish to receipt, milliseconds · lower is better',
          latency, False)
    svg.text(32, 900, 'Percentages compare Forte with Reverb on the same tier: stream_select with stream_select, ext-epoll with ext-uv. '
             'Bars are scaled within each group. Method, variables and raw data: BENCHMARKS.md', size=12, color='muted')
    svg.write(os.path.join(HERE, f'forte-vs-reverb-{theme}.svg'), 'Forte vs Reverb benchmark results')


def tmb_chart(theme):
    """The README's chart. It has no numbers on purpose; the numbers are in BENCHMARKS.md."""
    svg = Svg(860, 440, theme)
    t = svg.t
    svg.text(40, 58, 'Forte vs Reverb', size=34, weight=700, family=SCRAWL)
    svg.text(42, 86, 'a very scientific chart', size=16, color='muted', family=SCRAWL)
    ox, oy, top = 110, 360, 120
    svg.add(f'<path d="M{ox} {oy} L{ox} {top}" stroke="{t["text"]}" stroke-width="3" stroke-linecap="round" fill="none"/>'
            f'<path d="M{ox - 9} {top + 14} L{ox} {top} L{ox + 9} {top + 14}" stroke="{t["text"]}" stroke-width="3" '
            f'stroke-linecap="round" stroke-linejoin="round" fill="none"/>'
            f'<path d="M{ox} {oy} L{ox + 520} {oy + 3}" stroke="{t["text"]}" stroke-width="3" stroke-linecap="round" fill="none"/>')
    svg.text(70, 245, 'how good it is', size=17, anchor='middle', family=SCRAWL, extra=' transform="rotate(-90 70 245)"')
    # Reverb: a respectable little bar.
    svg.add(f'<path d="M190 {oy} L192 {oy - 92} L282 {oy - 95} L284 {oy}" fill="{t[("reverb", "uv")]}" stroke="{t["text"]}" '
            f'stroke-width="2.5" stroke-linejoin="round"/>')
    svg.text(237, oy + 34, 'Reverb', size=20, anchor='middle', family=SCRAWL)
    # Forte: leaves the chart.
    svg.add(f'<path d="M380 {oy} L378 70 L392 52 L404 74 L418 46 L432 70 L446 50 L460 72 L474 48 L476 {oy}" '
            f'fill="{t[("forte", "epoll")]}" stroke="{t["text"]}" stroke-width="2.5" stroke-linejoin="round"/>')
    svg.text(427, oy + 34, 'Forte', size=20, anchor='middle', weight=700, family=SCRAWL)
    svg.add(f'<path d="M560 118 C 530 112, 505 100, 488 84" stroke="{t["text"]}" stroke-width="2.2" fill="none" stroke-linecap="round"/>'
            f'<path d="M486 98 L486 82 L501 86" stroke="{t["text"]}" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>')
    svg.text(568, 126, 'off the chart', size=18, family=SCRAWL)
    svg.text(820, 300, 'source:', size=15, anchor='end', color='muted', family=SCRAWL)
    svg.text(820, 330, 'trust me bro', size=24, anchor='end', weight=700, family=SCRAWL, extra=' transform="rotate(-4 820 330)"')
    svg.text(820, 410, 'the actual science is in BENCHMARKS.md', size=13, anchor='end', color='muted', family=SANS)
    svg.write(os.path.join(HERE, f'tmb-{theme}.svg'), 'Forte vs Reverb: source, trust me bro')


def tables(heats, h4):
    def cells_for(h):
        return [c for c in CELLS if heats.get((h, c))]

    for h, cap, n in HEATS:
        cells = cells_for(h)
        print(f'\n### Heat {h}: {n:,} connections, nofile {cap}\n')
        print('| | ' + ' | '.join(NAMES[c] for c in cells) + ' |')
        print('|---|' + '---:|' * len(cells))
        rows = [
            ('Subscribe all clients', lambda r: f"{r['connect_s']:.2f} s"),
            ('Memory per idle connection', lambda r: f"{r['kb_per_conn']:.2f} KB"),
            ('Events/s, 1 s test', lambda r: f"{r['eps_1']:.1f}"),
            ('Events/s, 60 s test', lambda r: f"{r['eps_60']:.1f}"),
            ('Events accepted, 60 s', lambda r: f"{r['events_60']:,}"),
            ('Deliveries, 60 s', lambda r: f"{r['delivered_60']:,} ({r['ratio_60'] * 100:.0f}%)"),
            ('Deliveries/s incl. drain', lambda r: f"{r['dps_60']:,}"),
            ('Drain after publishing stopped', lambda r: f"{r['drain_s']:.1f} s"),
            ('Server CPU, 60 s test', lambda r: f"{r['cpu_60']:.2f} s"),
            ('Server CPU per delivery', lambda r: f"{r['us_per_delivery']:.2f} µs"),
            ('Server RSS peak', lambda r: f"{r['rss_peak_mb']:.0f} MB"),
            ('p50 / p99, 60 s test', lambda r: f"{r['p50_60']:,.0f} / {r['p99_60']:,.0f} ms"),
        ]
        for label, f in rows:
            print(f'| {label} | ' + ' | '.join(f(heats[(h, c)]) for c in cells) + ' |')

    print('\n### Heat 4: fixed rate, 950 connections, nofile 1024, five interleaved runs\n')
    print('| Median [min–max] | ' + ' | '.join(NAMES[c] for c in CELLS) + ' |')
    print('|---|' + '---:|' * len(CELLS))
    for r in (10, 25):
        for p in (50, 99):
            print(f'| p{p} @ {r}/s | ' + ' | '.join(
                f"{statistics.median(h4[c][r][f'p{p}']):.1f} [{min(h4[c][r][f'p{p}']):.1f}–{max(h4[c][r][f'p{p}']):.1f}] ms"
                for c in CELLS) + ' |')
        print(f'| Server CPU per 30 s @ {r}/s | ' + ' | '.join(f"{statistics.median(h4[c][r]['cpu']):.2f} s" for c in CELLS) + ' |')
    print('| Events with a delivery > 50 ms, 5 runs | ' + ' | '.join(
        str(h4[c][10]['stalled'] + h4[c][25]['stalled']) for c in CELLS) + ' |')
    print('| Delivered | ' + ' | '.join(
        f"{(h4[c][10]['delivered'] + h4[c][25]['delivered']) / (h4[c][10]['expected'] + h4[c][25]['expected']) * 100:.0f}%"
        for c in CELLS) + ' |')
    gc = {}
    for path in glob.glob(os.path.join(RESULTS, 'heat4-gc', '*.json')):
        server, loop = os.path.basename(path)[:-5].split('-')[1:]
        gc.setdefault((server, loop), []).append(json.load(open(path)))
    print('| GC runs / collector time per run | ' + ' | '.join(
        f"{statistics.median(g['runs'] for g in gc[c]):.0f} / {statistics.median(g['collector_time'] for g in gc[c]) * 1000:.1f} ms"
        for c in CELLS) + ' |')


if __name__ == '__main__':
    heats = {(h, c): flat_out(h, cap, n, *c) for h, cap, n in HEATS for c in CELLS}
    h4 = {c: fixed_rate(*c) for c in CELLS}
    for theme in THEMES:
        main_chart(theme, heats, h4)
        tmb_chart(theme)
    tables(heats, h4)
