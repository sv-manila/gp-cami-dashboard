{{--
    Shared identity list. Every page that shows more than one person uses this,
    so a row means the same thing in the review queue, the quality flags and the
    account lens.

    @param \Illuminate\Support\Collection|array $identities  narrow profile rows
    @param bool $showRecords  include the source-record count column
--}}
@php $showRecords = $showRecords ?? true; @endphp
<table class="doc-t">
    <tr>
        <th>Identity</th>
        <th>Name</th>
        <th>DOB</th>
        <th>NPI</th>
        <th>Location</th>
        @if ($showRecords)<th class="num-col">Records</th>@endif
        <th class="num-col">Cred.</th>
        <th class="num-col">Excl.</th>
        <th>Flags</th>
    </tr>
    @foreach ($identities as $p)
        <tr>
            <td><a href="{{ route('profile.show', $p->identity_id) }}">#{{ $p->identity_id }}</a></td>
            <td>{{ trim($p->first_name.' '.$p->last_name) ?: '—' }}</td>
            <td>{{ $p->date_of_birth ?: '—' }}</td>
            <td class="mono">{{ $p->npi ?: '—' }}</td>
            <td>{{ trim(($p->city ?: '').' '.($p->state ?: '')) ?: '—' }}</td>
            @if ($showRecords)<td class="num-col">{{ number_format($p->record_count) }}</td>@endif
            <td class="num-col">{{ number_format($p->credential_count) }}</td>
            <td class="num-col">{{ number_format($p->exclusion_count) }}</td>
            <td>
                @if ($p->has_active_exclusion)<span class="badge excl">exclusion</span>@endif
                @if ($p->has_active_board_action)<span class="badge excl">board action</span>@endif
                @if (! $p->has_active_exclusion && ! $p->has_active_board_action)<span class="muted">—</span>@endif
            </td>
        </tr>
    @endforeach
</table>
