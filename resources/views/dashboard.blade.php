@extends('layouts.app')
@section('title', 'Golden Profile Stats · gp-cami dashboard')

@section('content')
    <h1>Golden Profile Stats</h1>
    <p class="sub">Total data held in the gp-cami golden_profile hub.
        @if ($trends)
            <span class="muted">Sparkline and delta come from <code>gpdash:snapshot</code>.</span>
        @endif
    </p>

    <div class="cards">
        @foreach ($stats as $label => $s)
            @php
                $help = config('gpcami.stats_help')[$label] ?? null;
                $table = config('gpcami.stats_tables')[$label];
                $series = array_values($trends[$label] ?? []);
            @endphp
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
                    <div class="num" data-table="{{ $table }}">{{ ($s['approx'] ?? false) ? '≈ ' : '' }}{{ number_format($s['count']) }}</div>

                    <div class="card-foot">
                        @if (($s['delta'] ?? null) !== null)
                            {{-- A flat delta on a table that should be growing is the
                                 point of this line: "13.4M" looks identical whether
                                 the rollup ran last night or stalled a week ago. --}}
                            <span class="delta {{ $s['delta'] > 0 ? 'up' : ($s['delta'] < 0 ? 'down' : 'flat') }}">
                                {{ $s['delta'] > 0 ? '+' : '' }}{{ number_format($s['delta']) }}
                                <span class="muted">since last snapshot</span>
                            </span>
                        @endif
                        @if (count($series) > 1)
                            @include('partials.sparkline', ['series' => $series])
                        @endif
                    </div>

                    @if ($s['approx'] ?? false)
                        {{-- Not a busy hub: InnoDB has no stored row count, so an exact
                             COUNT(*) over 13M rows takes seconds. Estimate now, exact on request. --}}
                        <div class="approx-note">
                            estimate
                            <button type="button" class="btn-mini js-exact" data-table="{{ $table }}">count exactly</button>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    @if (! $trends)
        <div class="notice" style="margin-top:16px;">
            No snapshots recorded yet, so the cards have no trend to show.
            Run <code>php artisan gpdash:snapshot</code> to start the history.
        </div>
    @endif

    <h1 style="margin-top:28px;">Search</h1>
    <p class="sub">One box. Paste a name, an NPI, a DEA, a licence number, an employee id or an identity UUID —
        the shape of the term decides which index is used.</p>

    <form class="search smart" id="search-form" method="get" action="{{ route('dashboard') }}">
        <div class="field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" value="{{ $query }}" autofocus autocomplete="off"
                   placeholder="Smith, John · 1234567893 · lic:A54321/CA · emp:41822 · uuid:…">
        </div>
        <div class="field narrow">
            <label for="dob">Date of birth</label>
            <input type="date" id="dob" name="dob" value="{{ $dob }}">
        </div>
        <button class="btn" id="search-btn" type="submit">
            <span class="spinner" aria-hidden="true"></span>
            <span class="btn-label">Search</span>
        </button>

        <div class="search-opts">
            <label class="check"><input type="checkbox" name="prefix" value="1" @checked($prefix)> Prefix match</label>
            <label class="check"><input type="checkbox" name="excl" value="1" @checked($exclOnly)> Active exclusions only</label>
            <details class="syntax">
                <summary>Search syntax</summary>
                <table class="syntax-t">
                    <tr><td><code>Smith, John</code></td><td>surname + given name (also <code>John Smith</code> or just <code>Smith</code>)</td></tr>
                    <tr><td><code>1234567893</code></td><td>10 digits → NPI</td></tr>
                    <tr><td><code>AB1234563</code></td><td>2 letters + 7 digits → DEA</td></tr>
                    <tr><td><code>lic:A54321/CA</code></td><td>licence number, optional state</td></tr>
                    <tr><td><code>emp:41822</code></td><td>source employee id</td></tr>
                    <tr><td><code>id:5</code> · <code>uuid:…</code></td><td>identity id / UUID</td></tr>
                    <tr><td><code>mmis:…</code> · <code>dea:…</code></td><td>multi-valued identifiers</td></tr>
                    <tr><td><code>ssn4:6789 last:Smith</code></td><td>SSN last four — needs a surname, since <code>ssn_last_four</code> is not indexed alone</td></tr>
                    <tr><td><code>last:Smith</code></td><td>pins the surname; combines with any term above</td></tr>
                </table>
            </details>
        </div>

        <div class="search-status" id="search-status" role="status" aria-live="polite" hidden>
            Searching the hub…
        </div>
    </form>

    <div class="recents" id="recents" hidden>
        <span class="muted">Recent:</span>
        <span id="recents-list"></span>
        <button type="button" class="btn-mini" id="recents-clear">clear</button>
    </div>

    @if ($error)
        <div class="error">{{ $error }}</div>
    @endif

    @if ($suggestions)
        <div class="notice">
            No exact match. Surnames that do exist under the same opening letters:
            @foreach ($suggestions as $s)
                <a href="{{ route('dashboard', ['q' => $s['last_name']]) }}"><b>{{ $s['last_name'] }}</b></a><span class="muted">({{ number_format($s['n']) }})</span>{{ ! $loop->last ? ' · ' : '' }}
            @endforeach
        </div>
    @endif

    @if ($searched && ! $error && $results)
        @if ($results->isEmpty())
            <div class="notice">No records found for <b>{{ $query }}</b>.
                @if (! $prefix && $criteria['type'] === 'name')
                    Try <a href="{{ route('dashboard', array_merge(request()->query(), ['prefix' => 1])) }}">prefix match</a>.
                @endif
            </div>
        @else
            @php $qs = collect(request()->query())->except('cursor')->all(); @endphp
            <div class="results-head">
                <div>
                    <b>{{ $results->count() }}</b> on this page
                    @if ($matchedOn)<span class="muted">· matched on {{ $matchedOn }}</span>@endif
                </div>
                <div class="results-actions">
                    <a class="btn-mini" href="{{ route('search.export', array_merge($qs, ['format' => 'csv'])) }}">Export CSV</a>
                    <a class="btn-mini" href="{{ route('search.export', array_merge($qs, ['format' => 'json'])) }}">Export JSON</a>
                </div>
            </div>

            <div class="results-layout">
                <div class="results-list">
                    <div class="results-list-head">Identities</div>
                    <ul>
                        @foreach ($results as $r)
                            <li>
                                <button type="button" class="name-item {{ (string) $selected === (string) $r->identity_id ? 'active' : '' }}"
                                        data-id="{{ $r->identity_id }}">
                                    <span class="name-line">
                                        {{ trim($r->first_name.' '.$r->last_name) }}
                                        @if ($r->has_active_exclusion)<span class="dot excl" title="active exclusion"></span>@endif
                                    </span>
                                    <span class="name-meta muted">
                                        #{{ $r->identity_id }}
                                        @if ($r->date_of_birth) · {{ $r->date_of_birth }}@endif
                                        @if ($r->record_count > 1) · {{ number_format($r->record_count) }} records @endif
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="pager">
                        @if ($results->onFirstPage())
                            <span class="btn-mini disabled">← Previous</span>
                        @else
                            <a class="btn-mini" href="{{ $results->appends($qs)->previousPageUrl() }}">← Previous</a>
                        @endif
                        @if ($results->hasMorePages())
                            <a class="btn-mini" href="{{ $results->appends($qs)->nextPageUrl() }}">Next →</a>
                        @else
                            <span class="btn-mini disabled">Next →</span>
                        @endif
                    </div>
                </div>
                <div class="results-detail" id="detail-panel">
                    <div class="detail-placeholder muted">Select an identity to view full details.</div>
                </div>
            </div>
        @endif
    @endif

    <script>
        // Search is a plain GET round-trip against a 13M-row hub — show that
        // something is happening instead of a frozen-looking page.
        (function () {
            var form = document.getElementById('search-form');
            if (!form) return;
            var btn = document.getElementById('search-btn');
            var status = document.getElementById('search-status');
            form.addEventListener('submit', function () {
                rememberSearch(document.getElementById('q').value);
                btn.classList.add('loading');
                btn.disabled = true;
                btn.querySelector('.btn-label').textContent = 'Searching…';
                status.hidden = false;
            });
            // Back/forward cache restore leaves the button stuck mid-spin.
            window.addEventListener('pageshow', function (e) {
                if (!e.persisted) return;
                btn.classList.remove('loading');
                btn.disabled = false;
                btn.querySelector('.btn-label').textContent = 'Search';
                status.hidden = true;
            });
        })();

        // Recent lookups. Kept in localStorage rather than on the server: this
        // dashboard has no accounts, so "recent" can only mean "this browser".
        var RECENTS_KEY = 'gpcami.recents';

        function rememberSearch(term) {
            term = (term || '').trim();
            if (!term) return;
            var list = readRecents().filter(function (t) { return t !== term; });
            list.unshift(term);
            localStorage.setItem(RECENTS_KEY, JSON.stringify(list.slice(0, 8)));
        }

        function readRecents() {
            try { return JSON.parse(localStorage.getItem(RECENTS_KEY)) || []; }
            catch (e) { return []; }
        }

        (function () {
            var box = document.getElementById('recents');
            var list = document.getElementById('recents-list');
            if (!box) return;

            function render() {
                var items = readRecents();
                box.hidden = items.length === 0;
                list.innerHTML = '';
                items.forEach(function (term) {
                    var a = document.createElement('a');
                    a.className = 'chip';
                    a.href = '{{ route('dashboard') }}?q=' + encodeURIComponent(term);
                    a.textContent = term;
                    list.appendChild(a);
                });
            }

            document.getElementById('recents-clear').addEventListener('click', function () {
                localStorage.removeItem(RECENTS_KEY);
                render();
            });
            render();
        })();

        // "count exactly" on an estimated stat card.
        (function () {
            var base = "{{ url('/stats/exact') }}";
            document.querySelectorAll('.js-exact').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var note = btn.parentNode;
                    var num = note.parentNode.querySelector('.num');
                    btn.disabled = true;
                    btn.textContent = 'counting…';
                    fetch(base + '/' + btn.dataset.table, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, body: j }; }); })
                        .then(function (r) {
                            if (!r.ok) throw new Error(r.body.error || 'count failed');
                            num.textContent = r.body.count.toLocaleString();
                            note.textContent = 'exact';
                        })
                        .catch(function (e) {
                            btn.disabled = false;
                            btn.textContent = 'count exactly';
                            note.title = e.message;
                        });
                });
            });
        })();

        // Detail panel. The selected identity is written into the URL so a
        // search plus the profile someone is actually pointing at is one link.
        (function () {
            var panel = document.getElementById('detail-panel');
            if (!panel) return;
            var base = "{{ url('/profile') }}";

            function load(id, push) {
                document.querySelectorAll('.name-item.active').forEach(function (b) { b.classList.remove('active'); });
                var btn = document.querySelector('.name-item[data-id="' + id + '"]');
                if (btn) btn.classList.add('active');

                panel.innerHTML = '<div class="detail-placeholder muted">'
                    + '<span class="spinner dark"></span> Loading…</div>';

                if (push) {
                    var url = new URL(window.location.href);
                    url.searchParams.set('identity', id);
                    history.replaceState({}, '', url);
                }

                fetch(base + '/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
                    .then(function (html) { panel.innerHTML = html; })
                    .catch(function (e) { panel.innerHTML = '<div class="error">Failed to load details: ' + e.message + '</div>'; });
            }

            document.querySelectorAll('.name-item').forEach(function (btn) {
                btn.addEventListener('click', function () { load(btn.dataset.id, true); });
            });

            @if ($selected)
                load(@json((string) $selected), false);
            @endif
        })();
    </script>
@endsection
