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

    @unless ($indexed)
        <div class="notice" style="margin-top:16px;">
            {{-- No --apply command here on purpose: this page is unauthenticated
                 and that command ALTERs the shared hub. --}}
            <code>gp_source_link.account_id</code> has no index on the hub, so this member list is a capped
            scan. An operator can add that index to make it a range read.
        </div>
    @endunless

    <h2 class="section-h">Members</h2>

    @if ($error)
        <div class="error">{{ $error }}</div>
    @elseif ($identities->isEmpty())
        <div class="notice">No identities found under this account.</div>
    @else
        @include('partials.identity-table', ['identities' => $identities])
        @if ($truncated)
            <p class="sub muted">Showing the first {{ $maxMembers }} members.</p>
        @else
            <p class="sub muted">{{ $identities->count() }} member{{ $identities->count() === 1 ? '' : 's' }}.</p>
        @endif
    @endif
@endsection
