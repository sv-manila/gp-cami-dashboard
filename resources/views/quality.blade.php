@extends('layouts.app')
@section('title', 'Data quality · gp-cami dashboard')

@section('content')
    <h1>Data quality</h1>
    <p class="sub">How source records are spread across identities — and the identities that spread
        says are wrong. A healthy hub is almost entirely one-record identities; mass in the long tail
        is over-merge, where a name collision swallowed people who are not the same person.</p>

    @if (! $histogram || ! $total)
        <div class="notice">No snapshot yet. Run <code>php artisan gpdash:snapshot --only=buckets,quality</code>.</div>
    @else
        <h2 class="section-h">Source records per identity</h2>
        <div class="bars">
            @php $peak = max($histogram) ?: 1; @endphp
            @foreach ($histogram as $bucket => $n)
                <div class="bar-row">
                    <div class="bar-label">{{ $bucket }} record{{ $bucket === '1' ? '' : 's' }}</div>
                    <div class="bar-track">
                        {{-- Log scale: the '1' bucket holds 13.4M and the tail holds
                             single digits, so a linear bar would render every bucket
                             that matters as an invisible sliver. --}}
                        <div class="bar-fill {{ in_array($bucket, ['101-1000', '1000+']) ? 'crit' : (in_array($bucket, ['21-100']) ? 'warn' : '') }}"
                             style="width: {{ $n ? max(0.6, log10($n + 1) / log10($peak + 1) * 100) : 0 }}%"></div>
                    </div>
                    <div class="bar-value">{{ number_format($n) }}
                        <span class="muted">{{ $total ? number_format($n / $total * 100, 3) : 0 }}%</span></div>
                </div>
            @endforeach
        </div>
        <p class="sub muted">Log-scaled bars. {{ number_format($total) }} identities, snapshot {{ $capturedOn }}.</p>
    @endif

    <h2 class="section-h">Flagged identities</h2>
    <div class="tabs-inline">
        <a href="{{ route('quality') }}" class="{{ ! $activeFlag ? 'active' : '' }}">All ({{ $counts->sum() }})</a>
        @foreach ($counts as $flag => $n)
            <a href="{{ route('quality', ['flag' => $flag]) }}" class="{{ $activeFlag === $flag ? 'active' : '' }}">
                {{ str_replace('_', ' ', $flag) }} ({{ $n }})</a>
        @endforeach
    </div>

    @if ($flags->isEmpty())
        <div class="notice">
            @if ($flags->currentPage() > 1)
                {{-- An out-of-range page is not an empty hub. Saying "nothing
                     flagged" here read as "the snapshot has not run" on a filter
                     that has fifty rows on the pages before this one. --}}
                Nothing on page {{ $flags->currentPage() }} of this list —
                <a href="{{ $flags->url(1) }}">back to the first page</a>.
            @else
                Nothing flagged. Either the hub is clean or
                <code>gpdash:snapshot --only=quality</code> has not run yet.
            @endif
        </div>
    @else
        <div class="list-head">
            <div class="muted">
                {{ number_format($flags->total()) }} flagged
                {{ $activeFlag ? str_replace('_', ' ', $activeFlag).' ' : '' }}identit{{ $flags->total() === 1 ? 'y' : 'ies' }},
                heaviest first.
            </div>
            @include('partials.per-page', ['current' => $flags->perPage()])
        </div>
        <table class="doc-t">
            <tr><th>Identity</th><th>Name</th><th class="num-col">Records</th><th>Flag</th><th>Why</th><th></th></tr>
            @foreach ($flags as $f)
                <tr>
                    <td><a href="{{ route('profile.show', $f->identity_id) }}">#{{ $f->identity_id }}</a></td>
                    <td>{{ trim($f->first_name.' '.$f->last_name) ?: '—' }}</td>
                    <td class="num-col">{{ number_format($f->record_count) }}</td>
                    <td><span class="badge {{ $f->flag === \App\Models\QualityFlag::OVER_MERGE ? '' : 'excl' }}">
                        {{ str_replace('_', ' ', $f->flag) }}</span></td>
                    <td class="muted">{{ $f->detail }}</td>
                    <td><a class="btn-mini" href="{{ route('review.compare', ['a' => $f->identity_id]) }}">Compare</a></td>
                </tr>
            @endforeach
        </table>
        @include('partials.pager', ['paginator' => $flags, 'label' => 'flagged identities'])
        <p class="sub muted">
            A shared key is why records merged; a conflicting one is why they should not have.
            <code>over merge</code> flags size alone — <code>dob conflict</code> and <code>npi conflict</code>
            mean members of one identity carry different values for a key that should be unique to a person.
        </p>
    @endif
@endsection
