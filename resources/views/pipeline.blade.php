@extends('layouts.app')
@section('title', 'Pipeline freshness · gp-cami dashboard')

@section('content')
    <h1>Pipeline freshness</h1>
    <p class="sub">Is the hub current? A count of 13.4M profiles looks identical whether the sync ran an
        hour ago or stalled a fortnight back — this compares the watermark the sync advances against the
        newest row in the source.</p>

    @if ($error)
        <div class="error">{{ $error }}</div>
    @endif

    <div class="cards cards-4">
        <div class="card accent">
            <div class="label"><span>Sync status</span></div>
            <div class="num status-{{ $sync['level'] }}">{{ $sync['label'] }}</div>
            <div class="card-foot muted">
                @if ($sync['lag_hours'] !== null)
                    {{ number_format($sync['lag_hours'], 1) }}h behind the source
                @else
                    no comparable timestamp
                @endif
            </div>
        </div>
        <div class="card">
            <div class="label"><span>Consumed up to</span></div>
            <div class="num small">{{ $sync['mark']?->format('Y-m-d H:i') ?? '—' }}</div>
            <div class="card-foot muted">gp_watermark high_water</div>
        </div>
        <div class="card">
            <div class="label"><span>Source head</span></div>
            <div class="num small">{{ $sourceHead?->format('Y-m-d H:i') ?? '—' }}</div>
            <div class="card-foot muted">MAX(employees.date_modified)</div>
        </div>
        <div class="card">
            <div class="label"><span>Unresolved staged rows</span></div>
            <div class="num {{ ($backlog['backlog'] ?? 0) > 0 ? 'status-warn' : '' }}">
                {{ $backlog['backlog'] === null ? '—' : number_format($backlog['backlog']) }}
            </div>
            <div class="card-foot muted">staged minus linked</div>
        </div>
    </div>

    @if ($backlog['captured_on'])
        <div class="grid2" style="margin-top:18px;">
            <div class="spec">
                <h4>Where staged rows ended up</h4>
                <div class="kvrow"><span>Staged persons</span><span class="v">{{ number_format($backlog['staged']) }}</span></div>
                <div class="kvrow"><span>Linked to an identity</span><span class="v">{{ number_format($backlog['linked']) }}</span></div>
                <div class="kvrow"><span>Distinct identities</span><span class="v">{{ number_format($backlog['identities']) }}</span></div>
                <div class="kvrow"><span>Folded in by dedup</span><span class="v">{{ number_format($backlog['collapse']) }}</span></div>
                <p style="margin:10px 0 0;font-size:.8rem;color:var(--ink-faint);">
                    Dedup folding records into an existing identity is the pipeline working. A sudden jump in
                    that number is the first sign of an over-merging tier — the
                    <a href="{{ route('quality') }}">quality page</a> shows which identities absorbed them.
                </p>
            </div>
            <div class="spec">
                <h4>Snapshots</h4>
                <div class="kvrow"><span>Last recorded</span><span class="v">{{ $lastSnapshot ?? 'never' }}</span></div>
                <div class="kvrow"><span>Counts from</span><span class="v">{{ $backlog['captured_on'] }}</span></div>
                <p style="margin:10px 0 0;font-size:.8rem;color:var(--ink-faint);">
                    Every figure on this row is arithmetic over the nightly snapshot rather than an anti-join
                    across 13M rows. Refresh it with <code>php artisan gpdash:snapshot</code>.
                </p>
            </div>
        </div>
    @endif

    <h2 class="section-h">Watermarks</h2>
    @if (! $watermarks)
        <div class="notice">No sync watermark rows in <code>gp_watermark</code>.</div>
    @else
        <table class="doc-t">
            <tr><th>System</th><th>Source table</th><th>High water</th><th>Updated</th></tr>
            @foreach ($watermarks as $w)
                <tr>
                    <td class="mono">{{ $w->system_id }}</td>
                    <td class="mono">{{ $w->source_table }}</td>
                    <td class="mono">{{ $w->high_water }}</td>
                    <td class="muted">{{ $w->updated_at }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($checkpoints)
        <h2 class="section-h">Backfill checkpoints</h2>
        <p class="sub">One row per staging stripe, written so <code>gp:backfill</code> can resume where it
            stopped. These are progress markers, not sync state.</p>
        <table class="doc-t">
            <tr><th>Stripe</th><th>High water (source id)</th><th>Updated</th></tr>
            @foreach ($checkpoints as $c)
                <tr>
                    <td class="mono">{{ $c->source_table }}</td>
                    <td class="mono">{{ number_format((int) $c->high_water) }}</td>
                    <td class="muted">{{ $c->updated_at }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endsection
