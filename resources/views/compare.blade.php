@extends('layouts.app')
@section('title', 'Compare identities · gp-cami dashboard')

@section('content')
    <h1>Compare identities</h1>
    <p class="sub">Two identities side by side. Rows where the two disagree are highlighted — a
        disagreement on a high-precision key (NPI, DEA, date of birth) is the decisive signal that
        these are not the same person.</p>

    <form class="search" method="get" action="{{ route('review.compare') }}">
        <div class="field"><label for="a">Identity A</label>
            <input type="number" id="a" name="a" value="{{ $ids[0] ?? '' }}" required></div>
        <div class="field"><label for="b">Identity B</label>
            <input type="number" id="b" name="b" value="{{ $ids[1] ?? '' }}" required></div>
        <button class="btn" type="submit"><span class="btn-label">Compare</span></button>
    </form>

    @if (count($ids) < 2)
        <div class="notice">Give two identity ids to compare.</div>
    @else
        @php
            $a = $profiles[$ids[0]] ?? null;
            $b = $profiles[$ids[1]] ?? null;
            $missing = collect($ids)->reject(fn ($id) => $profiles->has($id));
        @endphp

        @if ($missing->isNotEmpty())
            <div class="error">No identity found for #{{ $missing->implode(', #') }}.</div>
        @else
            @php
                $differing = collect($fields)->filter(fn ($label, $col) => (string) $a->{$col} !== (string) $b->{$col});
            @endphp
            <div class="notice">
                {{ $differing->count() }} of {{ count($fields) }} compared fields differ.
                @if ($differing->keys()->intersect(['npi', 'dea_number', 'date_of_birth'])->isNotEmpty())
                    <b>Includes a high-precision key</b> — these are very unlikely to be one person.
                @endif
            </div>

            <table class="doc-t compare-t">
                <tr>
                    <th>Field</th>
                    <th><a href="{{ route('profile.show', $a->identity_id) }}">#{{ $a->identity_id }}</a>
                        {{ trim($a->first_name.' '.$a->last_name) }}</th>
                    <th><a href="{{ route('profile.show', $b->identity_id) }}">#{{ $b->identity_id }}</a>
                        {{ trim($b->first_name.' '.$b->last_name) }}</th>
                </tr>
                @foreach ($fields as $col => $label)
                    @php $differs = (string) $a->{$col} !== (string) $b->{$col}; @endphp
                    <tr class="{{ $differs ? 'row-differs' : '' }}">
                        <td>{{ $label }}</td>
                        <td class="mono">{{ $a->{$col} === null || $a->{$col} === '' ? '—' : $a->{$col} }}</td>
                        <td class="mono">{{ $b->{$col} === null || $b->{$col} === '' ? '—' : $b->{$col} }}</td>
                    </tr>
                @endforeach
            </table>

            <h2 class="section-h">Merge basis</h2>
            <p class="sub">Why each side's own source records were joined together.
                <code>gp_source_link.match_key</code> is stamped at first link and never rewritten by a
                later dedup merge, so this is derived from what the members demonstrably share.</p>

            <div class="grid2">
                @foreach ($ids as $id)
                    @php $side = $basis[$id] ?? ['basis' => [], 'conflicts' => [], 'source' => 'unavailable']; @endphp
                    <div class="spec">
                        <h4>#{{ $id }} <span class="muted" style="font-weight:400;">· {{ $side['source'] }}</span></h4>
                        @forelse ($side['basis'] as $label => $hit)
                            <div class="kvrow"><span>{{ $label }}</span>
                                <span class="v">{{ $hit['value'] }} <span class="muted">({{ $hit['members'] }})</span></span></div>
                        @empty
                            <p class="muted" style="font-size:.85rem;margin:0;">No shared key recorded.</p>
                        @endforelse

                        @if ($side['conflicts'])
                            <p class="eyebrow" style="margin-top:12px;">Conflicts</p>
                            @foreach ($side['conflicts'] as $label => $c)
                                <div class="kvrow conflict"><span>{{ $label }}</span>
                                    <span class="v">{{ implode(' · ', $c['values']) }}</span></div>
                            @endforeach
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
@endsection
