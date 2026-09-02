@extends('layouts.app')
@section('title', 'Review queue · gp-cami dashboard')

@section('content')
    <h1>Review queue</h1>
    <p class="sub">Every source link the resolver was not confident about, weakest score first.
        Deterministic links (SSN, NPI, DEA, licence) score 0.99 and never appear here — this is the
        probabilistic residual, the 0.75–0.92 band the pipeline deliberately hands to a human.</p>

    @if ($summary['captured_on'])
        <div class="cards cards-4">
            @foreach ($summary['states'] as $label => $n)
                <div class="card">
                    <div class="label"><span>{{ $label }}</span></div>
                    <div class="num">{{ number_format($n) }}</div>
                    <div class="card-foot muted">links</div>
                </div>
            @endforeach
        </div>

        <h2 class="section-h">Score bands</h2>
        <div class="bars">
            @php $bandTotal = max(array_sum($summary['bands']), 1); @endphp
            @foreach ($summary['bands'] as $band => $n)
                <div class="bar-row">
                    <div class="bar-label">{{ $band }}</div>
                    <div class="bar-track">
                        <div class="bar-fill {{ str_contains($band, 'review band') ? 'warn' : '' }}"
                             style="width: {{ max(0.4, $n / $bandTotal * 100) }}%"></div>
                    </div>
                    <div class="bar-value">{{ number_format($n) }}</div>
                </div>
            @endforeach
        </div>
        <p class="sub muted">Counts from the snapshot taken {{ $summary['captured_on'] }} —
            grouping 13.4M links live is a batch job's work, not a page's.</p>
    @else
        <div class="notice">No snapshot yet, so the queue depth is unknown.
            Run <code>php artisan gpdash:snapshot --only=links</code>.</div>
    @endif

    <h2 class="section-h">Open links</h2>
    <div class="tabs-inline">
        <a href="{{ route('review', ['state' => 'open']) }}" class="{{ $state !== 'pinned' ? 'active' : '' }}">Needs review</a>
        <a href="{{ route('review', ['state' => 'pinned']) }}" class="{{ $state === 'pinned' ? 'active' : '' }}">Pinned</a>
    </div>

    @if ($error)
        <div class="error">{{ $error }}</div>
    @elseif (! $links)
        <div class="notice">
            @if ($queue->currentPage() > 1)
                Nothing on page {{ $queue->currentPage() }} of this queue —
                <a href="{{ $queue->url(1) }}">back to the first page</a>.
            @else
                Nothing in this queue — every link in the hub resolved on a deterministic key.
                That is the expected state after a clean backfill; rows land here when the probabilistic
                pass scores a pair inside the review band.
            @endif
        </div>
    @else
        <div class="list-head">
            <div class="muted">
                Links {{ number_format($queue->firstItem()) }}–{{ number_format($queue->lastItem()) }}, weakest score first.
            </div>
            @include('partials.per-page', ['current' => $perPage])
        </div>
        <table class="doc-t">
            <tr>
                <th>Link</th><th>Identity</th><th>Name</th><th>Source row</th>
                <th>Method</th><th>Key</th><th class="num-col">Score</th><th>State</th><th>Linked</th><th></th>
            </tr>
            @foreach ($links as $l)
                @php $p = $names[(int) $l->identity_id] ?? null; @endphp
                <tr>
                    <td class="mono">{{ $l->link_id }}</td>
                    <td><a href="{{ route('profile.show', $l->identity_id) }}">#{{ $l->identity_id }}</a></td>
                    <td>{{ $p ? trim($p->first_name.' '.$p->last_name) : '—' }}</td>
                    <td class="mono">{{ $l->source_table }}:{{ $l->source_id }}</td>
                    <td>{{ $l->match_method }}</td>
                    <td class="mono">{{ $l->match_key ?: '—' }}</td>
                    <td class="num-col">{{ $l->match_score === null ? '—' : number_format((float) $l->match_score, 4) }}</td>
                    <td>
                        <span class="badge {{ $l->match_state === 'review' ? 'excl' : '' }}">{{ $l->match_state }}</span>
                        @if ($l->is_pinned)<span class="badge">pinned</span>@endif
                    </td>
                    <td class="muted">{{ $l->linked_at }}</td>
                    <td><a class="btn-mini" href="{{ route('review.compare', ['a' => $l->identity_id]) }}">Compare</a></td>
                </tr>
            @endforeach
        </table>
        @include('partials.pager', ['paginator' => $queue])
        @if ($depthCap)
            <p class="sub muted">The queue stops paging here. A queue this deep is a pipeline problem
                rather than a review backlog — see the score bands above.</p>
        @endif
    @endif

    <h2 class="section-h">Compare two identities</h2>
    <p class="sub">The merge decision itself: canonical fields side by side, differences called out,
        with each side's shared and conflicting keys.</p>
    <form class="search" method="get" action="{{ route('review.compare') }}">
        <div class="field"><label for="a">Identity A</label><input type="number" id="a" name="a" required></div>
        <div class="field"><label for="b">Identity B</label><input type="number" id="b" name="b" required></div>
        <button class="btn" type="submit"><span class="btn-label">Compare</span></button>
    </form>
@endsection
