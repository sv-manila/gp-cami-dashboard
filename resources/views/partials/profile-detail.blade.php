@php $r = $profile; @endphp
<div class="detail-head">
    <div class="detail-name">{{ trim($r->first_name.' '.$r->middle_name.' '.$r->last_name) }}</div>
    <div class="detail-uuid muted">{{ $r->identity_uuid }}</div>
    @if ($r->has_active_exclusion)
        <span class="badge excl">ACTIVE EXCLUSION</span>
    @else
        <span class="badge ok">clear</span>
    @endif
</div>

<table class="kv">
    <tr><th>Date of birth</th><td>{{ $r->date_of_birth ?: '—' }}</td></tr>
    <tr><th>SSN (last 4)</th><td>{{ $r->ssn_last_four ? '****'.$r->ssn_last_four : '—' }}</td></tr>
    <tr><th>NPI</th><td>{{ $r->npi ?: '—' }}</td></tr>
    <tr><th>DEA</th><td>{{ $r->dea_number ?: '—' }}</td></tr>
    <tr><th>UPIN</th><td>{{ $r->upin ?: '—' }}</td></tr>
    <tr><th>Location</th><td>{{ trim(implode(', ', array_filter([$r->address1, $r->city, $r->state, $r->zip]))) ?: '—' }}</td></tr>
    <tr><th>Confidence</th><td>{{ $r->confidence }}</td></tr>
    <tr><th>Records</th><td>{{ number_format($r->record_count) }}</td></tr>
    <tr><th>Accounts</th><td>{{ number_format($r->account_count) }}</td></tr>
    <tr><th>Identifiers</th><td>{{ isset($r->identifier_count) ? number_format($r->identifier_count) : '—' }}</td></tr>
    <tr><th>Credentials</th><td>{{ number_format($r->credential_count) }}</td></tr>
    <tr><th>Exclusions</th><td>{{ number_format($r->exclusion_count) }}</td></tr>
    <tr><th>Last updated</th><td>{{ $r->last_updated ?: '—' }}</td></tr>
</table>

@foreach (config('gpcami.profile_json') as $key)
    @php $val = $r->{$key}; @endphp
    @if (! empty($val))
        <div class="detail-section">
            <div class="detail-section-title">{{ ucfirst(str_replace('_', ' ', $key)) }}
                <span class="muted">({{ is_array($val) ? count($val) : 1 }})</span></div>
            <pre class="json">{{ json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif
@endforeach
