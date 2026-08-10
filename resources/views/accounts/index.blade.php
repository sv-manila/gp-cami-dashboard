@extends('layouts.app')
@section('title', 'Accounts · gp-cami dashboard')

@section('content')
    <h1>Accounts</h1>
    <p class="sub">The profile page's <code>accounts</code> rollup read the other way round: which people
        are in an account, not which accounts a person appears under.</p>

    <form class="search" method="get" action="{{ route('accounts') }}">
        <div class="field grow">
            <label for="q">Account name or id</label>
            <input type="text" id="q" name="q" value="{{ $q }}" autocomplete="off">
        </div>
        <button class="btn" type="submit"><span class="btn-label">Filter</span></button>
    </form>

    @if ($accounts->isEmpty())
        <div class="notice">
            @if ($q)
                No account matches <b>{{ $q }}</b>.
            @else
                No account rollup yet. Run <code>php artisan gpdash:snapshot --only=accounts</code> —
                <code>gp_source_link.account_id</code> is unindexed on the hub, so grouping by it is a
                full scan and belongs in the nightly batch rather than in a page load.
            @endif
        </div>
    @else
        <table class="doc-t">
            <tr><th>Account</th><th>Name</th><th class="num-col">Identities</th><th class="num-col">Source links</th><th class="num-col">Records per identity</th></tr>
            @foreach ($accounts as $a)
                <tr>
                    <td><a href="{{ route('accounts.show', $a->account_id) }}">#{{ $a->account_id }}</a></td>
                    <td>{{ $a->account_name ?: '—' }}</td>
                    <td class="num-col">{{ number_format($a->identity_count) }}</td>
                    <td class="num-col">{{ number_format($a->link_count) }}</td>
                    <td class="num-col">{{ $a->identity_count ? number_format($a->link_count / $a->identity_count, 2) : '—' }}</td>
                </tr>
            @endforeach
        </table>
        <p class="sub muted">Rolled up {{ $stale?->diffForHumans() ?? 'never' }}. Names come from the CAMI
            source database; the hub stores account ids only.</p>
    @endif
@endsection
