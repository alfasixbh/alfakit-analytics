@php
    /** @var \AlfaSix\AlfakitAnalytics\Support\Report $report */
    $max = max(1, ...array_map(static fn ($value) => (float) ($value ?? 0), array_merge($chart['current'], $chart['previous'] ?? [])));
    $count = max(1, count($chart['current']) - 1);
    $points = static function (array $values) use ($max, $count): string {
        $points = [];
        foreach (array_values($values) as $index => $value) {
            $points[] = round($index / $count * 1000, 2).','.round(200 - ((float) ($value ?? 0)) / $max * 190, 2);
        }
        return implode(' ', $points);
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('alfakit-analytics::analytics.shared.title', ['site' => $report->site->name]) }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --card: #ffffff; --fg: #0f172a; --muted: #64748b; --border: #e2e8f0; --accent: #2563eb; --bar: rgba(37, 99, 235, .12); --good: #15803d; --bad: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --alfakit-credit-emblem: #ffffff; --bg: #0b1120; --card: #111827; --fg: #e5e7eb; --muted: #94a3b8; --border: #1f2937; --accent: #60a5fa; --bar: rgba(96, 165, 250, .16); --good: #4ade80; --bad: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: inherit; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 24px 16px 48px; }
        header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        h1 { font-size: 20px; margin: 0; }
        .muted { color: var(--muted); }
        .periods, .filters { display: flex; flex-wrap: wrap; gap: 6px; }
        .chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border: 1px solid var(--border); border-radius: 999px; background: var(--card); text-decoration: none; font-size: 13px; }
        .chip[aria-current="true"] { border-color: var(--accent); color: var(--accent); font-weight: 600; }
        .grid { display: grid; gap: 16px; }
        .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 16px; }
        @media (min-width: 768px) { .kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } .lists { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .kpis { grid-template-columns: repeat(6, minmax(0, 1fr)); } }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; min-width: 0; }
        .kpi .label { color: var(--muted); font-size: 13px; }
        .kpi .value { font-size: 22px; font-weight: 600; }
        .good { color: var(--good); } .bad { color: var(--bad); }
        .chart svg { width: 100%; height: 220px; display: block; }
        .list h2 { font-size: 15px; margin: 0 0 4px; }
        .list h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 12px 0 6px; font-weight: 600; }
        .row { position: relative; display: flex; justify-content: space-between; gap: 12px; padding: 5px 8px; border-radius: 6px; text-decoration: none; }
        .row .bar { position: absolute; inset-block: 0; inset-inline-start: 0; background: var(--bar); border-radius: 6px; }
        .row span { position: relative; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .row:hover, .row:focus-visible { outline: 2px solid var(--border); }
        .row.active { font-weight: 600; }
        .empty { color: var(--muted); padding: 8px; }
        footer { margin-top: 24px; text-align: center; font-size: 12px; }
    </style>
</head>
<body>
<main class="wrap">
    <header>
        <div>
            <h1>{{ $report->site->name }}</h1>
            <div class="muted">{{ $report->site->domain }} · {{ $report->currentPeriodLabel() }}</div>
        </div>
        <nav class="periods" aria-label="{{ __('alfakit-analytics::analytics.shared.period') }}">
            @foreach ($presets as $preset)
                <a class="chip" href="{{ $report->urlForPreset($preset) }}" @if ($report->period->preset === $preset) aria-current="true" @endif>{{ \AlfaSix\AlfakitAnalytics\Support\Report::periodLabel($preset) }}</a>
            @endforeach
            <a class="chip" href="{{ $report->urlToggleCompare() }}" @if ($report->compare) aria-current="true" @endif>{{ $report->compare ? __('alfakit-analytics::analytics.compare.disable') : __('alfakit-analytics::analytics.compare.enable') }}</a>
        </nav>
    </header>

    @if ($report->filterLabels() !== [])
        <div class="filters" style="margin-bottom: 16px">
            @foreach ($report->filterLabels() as $dimension => $label)
                <a class="chip" href="{{ $report->urlWithoutFilter($dimension) }}" aria-label="{{ __('alfakit-analytics::analytics.filters.remove') }}: {{ $label }}">{{ $label }} <span aria-hidden="true">×</span></a>
            @endforeach
        </div>
    @endif

    <section class="grid kpis" aria-label="{{ __('alfakit-analytics::analytics.shared.summary') }}">
        <div class="card kpi">
            <div class="label">{{ __('alfakit-analytics::analytics.metrics.current_visitors') }}</div>
            <div class="value">{{ \AlfaSix\AlfakitAnalytics\Support\Format::number($current) }}</div>
        </div>
        @foreach ($kpis as $kpi)
            @continue($kpi['key'] === 'visits')
            <div class="card kpi">
                <div class="label">{{ $kpi['label'] }}</div>
                <div class="value">{{ $kpi['display'] }}</div>
                @if ($kpi['changeDisplay'] !== null)
                    <div class="{{ $kpi['good'] === true ? 'good' : ($kpi['good'] === false ? 'bad' : 'muted') }}" style="font-size: 12px">{{ __('alfakit-analytics::analytics.compare.change', ['change' => $kpi['changeDisplay']]) }}</div>
                @endif
            </div>
        @endforeach
    </section>

    <section class="card chart" style="margin-bottom: 16px">
        <h2 style="font-size: 15px; margin: 0 0 8px">{{ __('alfakit-analytics::analytics.chart.heading') }}</h2>
        <svg viewBox="0 0 1000 210" preserveAspectRatio="none" role="img" aria-label="{{ __('alfakit-analytics::analytics.chart.heading') }}" @if ($direction === 'rtl') style="transform: scaleX(-1)" @endif>
            @if ($chart['previous'] !== null)
                <polyline fill="none" stroke="var(--muted)" stroke-width="2" stroke-dasharray="6 4" vector-effect="non-scaling-stroke" points="{{ $points($chart['previous']) }}" />
            @endif
            <polyline fill="none" stroke="var(--accent)" stroke-width="2.5" vector-effect="non-scaling-stroke" points="{{ $points($chart['current']) }}" />
        </svg>
        <table style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)">
            <caption>{{ __('alfakit-analytics::analytics.chart.heading') }}</caption>
            @foreach ($chart['labels'] as $index => $label)
                <tr><th scope="row">{{ $label }}</th><td>{{ \AlfaSix\AlfakitAnalytics\Support\Format::number($chart['current'][$index] ?? 0) }}</td></tr>
            @endforeach
        </table>
        <div class="muted" style="display: flex; justify-content: space-between; font-size: 12px">
            <span>{{ $chart['labels'][0] ?? '' }}</span><span>{{ $chart['labels'][count($chart['labels']) - 1] ?? '' }}</span>
        </div>
    </section>

    <section class="grid lists">
        @foreach ($panels as $panel => $dimensions)
            <div class="card list">
                <h2>{{ __('alfakit-analytics::analytics.panels.'.$panel) }}</h2>
                @foreach ($dimensions as $dimension => $rows)
                    @if (count($dimensions) > 1)
                        <h3>{{ \AlfaSix\AlfakitAnalytics\Support\Dimension::label($dimension) }}</h3>
                    @endif
                    @forelse ($rows as $row)
                        @if ($row['url'] !== '')
                            <a class="row @if ($row['active']) active @endif" href="{{ $row['url'] }}" @if ($row['active']) aria-current="true" @endif>
                        @else
                            <div class="row">
                        @endif
                            <i class="bar" style="width: {{ $row['bar'] * 100 }}%"></i>
                            <span>@if ($row['flag']) <span aria-hidden="true">{{ $row['flag'] }}</span> @endif{{ $row['label'] }}</span>
                            <span>{{ $row['display'][0] }}@if ($row['changeDisplay'] !== null) <small class="{{ ($row['change'] ?? 0) >= 0 ? 'good' : 'bad' }}">{{ $row['changeDisplay'] }}</small>@endif</span>
                        @if ($row['url'] !== '')
                            </a>
                        @else
                            </div>
                        @endif
                    @empty
                        <div class="empty">{{ __('alfakit-analytics::analytics.panels.empty') }}</div>
                    @endforelse
                @endforeach
            </div>
        @endforeach
    </section>

    <footer class="muted">{{ __('alfakit-analytics::analytics.shared.footer') }}</footer>
    @if ($poweredBy ?? null)
        <div class="muted">{!! $poweredBy !!}</div>
    @endif
</main>
</body>
</html>
