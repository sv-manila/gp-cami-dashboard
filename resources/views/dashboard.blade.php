@extends('layouts.app')
@section('title', 'Golden Profile Stats · gp-cami dashboard')

@section('content')
    <h1>Golden Profile Stats</h1>
    <p class="sub">Total data held in the gp-cami golden_profile hub.</p>

    <div class="cards">
        @foreach ($stats as $label => $s)
            @php $help = config('gpcami.stats_help')[$label] ?? null; @endphp
            <div class="card accent">
                <div class="label">
                    <span>{{ $label }}</span>
                    @if ($help)
                        <span class="info" tabindex="0" aria-label="{{ $help }}">
                            i<span class="tip">{{ $help }}</span>
                        </span>
                    @endif
                </div>
                @if ($s['error'])
                    <div class="num err">{{ $s['error'] }}</div>
                @else
                    <div class="num">{{ ($s['approx'] ?? false) ? '≈ ' : '' }}{{ number_format($s['count']) }}</div>
                    @if ($s['approx'] ?? false)
                        <div class="approx-note">approx · hub busy</div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    <h1 style="margin-top:28px;">Search</h1>
    <p class="sub">Find all gp-cami data under a name. First and last name only.</p>

    <form class="search" method="get" action="{{ route('dashboard') }}">
        <div class="field">
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" value="{{ $first }}" autofocus>
        </div>
        <div class="field">
            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" value="{{ $last }}">
        </div>
        <button class="btn" type="submit">Search</button>
    </form>

    @if ($error)
        <div class="error">{{ $error }}</div>
    @endif

    @if ($searched && ! $error)
        @if ($results->isEmpty())
            <div class="notice">No records found for
                <b>{{ trim($first.' '.$last) ?: '(empty)' }}</b>.</div>
        @else
            <div class="results-layout">
                <div class="results-list">
                    <div class="results-list-head">{{ $results->count() }} identit{{ $results->count() === 1 ? 'y' : 'ies' }}</div>
                    <ul>
                        @foreach ($results as $r)
                            <li>
                                <button type="button" class="name-item" data-id="{{ $r->identity_id }}">
                                    {{ trim($r->first_name.' '.$r->last_name) }}
                                    @if ($r->has_active_exclusion)<span class="dot excl" title="active exclusion"></span>@endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="results-detail" id="detail-panel">
                    <div class="detail-placeholder muted">Select an identity to view full details.</div>
                </div>
            </div>
        @endif
    @endif

    <script>
        (function () {
            var panel = document.getElementById('detail-panel');
            if (!panel) return;
            var base = "{{ url('/profile') }}";
            document.querySelectorAll('.name-item').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.name-item.active').forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    panel.innerHTML = '<div class="detail-placeholder muted">Loading…</div>';
                    fetch(base + '/' + btn.dataset.id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
                        .then(function (html) { panel.innerHTML = html; })
                        .catch(function (e) { panel.innerHTML = '<div class="error">Failed to load details: ' + e.message + '</div>'; });
                });
            });
        })();
    </script>
@endsection
