@extends('layouts.app')
@section('title', 'Account #'.$account.' · gp-cami dashboard')

@section('content')
    <h1>Account #{{ $account }}{{ $rollup?->account_name ? ' — '.$rollup->account_name : '' }}</h1>
    <p class="sub"><a href="{{ route('accounts') }}">← All accounts</a></p>

    @if ($rollup)
        <div class="cards cards-4">
            <div class="card accent">
                <div class="label"><span>Identities</span></div>
                <div class="num">{{ number_format($rollup->identity_count) }}</div>
                <div class="card-foot muted">distinct people</div>
            </div>
            <div class="card">
                <div class="label"><span>Source links</span></div>
                <div class="num">{{ number_format($rollup->link_count) }}</div>
                <div class="card-foot muted">employee rows</div>
            </div>
            <div class="card">
                <div class="label"><span>Records per identity</span></div>
                <div class="num">{{ $rollup->identity_count ? number_format($rollup->link_count / $rollup->identity_count, 2) : '—' }}</div>
                <div class="card-foot muted">above ~1.1 means duplicates in the roster</div>
            </div>
            <div class="card">
                <div class="label"><span>Rolled up</span></div>
                <div class="num small">{{ $rollup->captured_at?->format('Y-m-d') ?? '—' }}</div>
                <div class="card-foot muted">gpdash:snapshot</div>
            </div>
        </div>
    @endif

    @unless ($paged)
        <div class="notice" style="margin-top:16px;">
            {{-- No --apply command here on purpose: this page is unauthenticated
                 and that command ALTERs the shared hub. --}}
            @if ($index === 'none')
                <code>gp_source_link.account_id</code> has no index on the hub, so this member list is a
                capped scan and cannot be paged: ordering it is a sort over all 12.5M links, which does not
                finish inside the query budget.
            @else
                <code>gp_source_link.account_id</code> is indexed, but not together with
                <code>identity_id</code>, so the members can be looked up quickly and still not be put in a
                stable order cheaply. The list is capped rather than paged until that composite index exists.
            @endif
            An operator can add the index the advisor asks for to page this list in full.
        </div>
    @endunless

    <h2 class="section-h">Members</h2>

    @if ($error)
        <div class="error">{{ $error }}</div>
    @elseif ($members->isEmpty())
        <div class="notice">
            @if ($members->currentPage() > 1)
                No members on page {{ $members->currentPage() }} —
                <a href="{{ $members->url(1) }}">back to the first page</a>.
            @else
                No identities found under this account.
            @endif
        </div>
    @else
        @if ($paged)
            <div class="list-head">
                <div class="muted">
                    Members {{ number_format($members->firstItem()) }}–{{ number_format($members->lastItem()) }},
                    by identity id{{ $rollup ? ' of '.number_format($rollup->identity_count).' in the rollup' : '' }}.
                </div>
                @include('partials.per-page', ['current' => $members->perPage()])
            </div>
        @endif
        @include('partials.identity-table', ['identities' => $members])
        @include('partials.pager', ['paginator' => $members])
        @if ($truncated)
            <p class="sub muted">Showing the first {{ number_format($maxMembers) }} members.</p>
        @elseif ($depthCap)
            {{-- Each page is its own read of gp_source_link, so the list stops
                 rather than letting a link walk the hub indefinitely. --}}
            <p class="sub muted">The member list stops at {{ number_format($maxMembers) }}. Use the
                <a href="{{ route('dashboard') }}">search</a> to reach a specific person in this account.</p>
        @elseif (! $paged)
            <p class="sub muted">{{ $members->count() }} member{{ $members->count() === 1 ? '' : 's' }}.</p>
        @endif
    @endif
@endsection
