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
    {{-- gp_identity.confidence is hardcoded 1.0 at creation and never recomputed,
         so it says nothing. The per-record match scores below do. --}}
    <tr><th>Employee records</th><td>{{ number_format($r->record_count) }}</td></tr>
    <tr><th>Accounts</th><td>{{ number_format($r->account_count) }}</td></tr>
    <tr><th>Identifiers</th><td>{{ isset($r->identifier_count) ? number_format($r->identifier_count) : '—' }}</td></tr>
    <tr><th>Credentials</th><td>{{ number_format($r->credential_count) }}</td></tr>
    <tr><th>Exclusions</th><td>{{ number_format($r->exclusion_count) }}</td></tr>
    <tr><th>Last updated</th><td>{{ $r->last_updated ?: '—' }}</td></tr>
</table>

@php
    $basis = ($basis ?? []) + ['links' => [], 'link_total' => 0, 'by_key' => [], 'audit' => [], 'shared' => [],
        'conflicts' => [], 'basis_source' => null, 'basis_truncated' => false,
        'derive_skipped' => false, 'weakest' => null, 'needs_review' => 0, 'pinned' => 0, 'error' => null];
    $oversized = $oversized ?? [];

    // Plain-English name for each match key, for a reader who has never heard
    // of a "match key". Phrased to finish the sentence "this record is here
    // because it has the …".
    $why = [
        'new' => 'Started this profile',
        'ssn_hash' => 'Same Social Security number',
        'npi' => 'Same NPI (national provider ID)',
        'dea_number' => 'Same DEA number',
        'upin' => 'Same UPIN',
        'license_registry' => 'Same license number',
        'name_dob' => 'Same name and date of birth',
        'probabilistic' => 'Looks like the same person — needs review',
    ];
    $sharedLabels = [
        'npi' => 'the same NPI', 'ssn' => 'the same Social Security number',
        'dea' => 'the same DEA number', 'upin' => 'the same UPIN',
        'mmis' => 'the same MMIS number', 'name + dob' => 'the same name and date of birth',
        // Conflict labels reuse this map, so the plain-English phrasing has to
        // read correctly after both "they all have …" and "they disagree on …".
        'date of birth' => 'the same date of birth', 'name' => 'the same name',
        // match_key spellings, for the "matched at intake" sentence
        'ssn_hash' => 'the same Social Security number', 'dea_number' => 'the same DEA number',
        'license_registry' => 'the same license number', 'license' => 'the same license number',
        'name_dob' => 'the same name and date of birth',
    ];
    $certainty = function ($score) {
        if ($score === null) return ['—', ''];
        if ($score >= 0.99) return ['Very high', 'matched on an ID unique to one person'];
        if ($score >= 0.95) return ['Medium', 'matched on name and date of birth, which two people can share'];
        return ['Low', 'a person should check this'];
    };
    // 'new' means "this record created the profile" — a 1.00 there is not a
    // match score, so it must not be read as certainty.
    $matched = array_values(array_filter($basis['links'], fn ($l) => $l->match_key !== 'new'));
    $matchedWeakest = null;
    foreach ($matched as $l) {
        $s = $l->match_score === null ? null : (float) $l->match_score;
        if ($s !== null && ($matchedWeakest === null || $s < $matchedWeakest)) $matchedWeakest = $s;
    }
    [$certWord, $certWhy] = $certainty($matchedWeakest);
    $total = (int) $basis['link_total'];
@endphp

<div class="detail-section">
    <div class="detail-section-title">Why these records are one person</div>

    @if ($basis['error'])
        <div class="error">This information is temporarily unavailable.</div>
    @else
        <div class="plain">
            @if ($total <= 1)
                <p>This profile comes from a <b>single employee record</b>. Nothing was combined.</p>
            @else
                <p>
                    <b>{{ number_format($total) }} employee records</b> were combined into this one person
                    @if ($basis['shared'])
                        {{-- basis_truncated means the shared keys were derived from a
                             SAMPLE of members, not all of them, so "they all have" is a
                             claim the data does not support. Identity 13332548 asserted
                             1,342 records shared one name while the hub holds two
                             distinct names among them. The flag was already plumbed
                             through to here and simply never read. --}}
                        @if ($basis['basis_truncated'])
                            — every record that was checked shares
                        @else
                            because they all have
                        @endif
                        @foreach ($basis['shared'] as $label => $s)<b>{{ $sharedLabels[$label] ?? $label }}</b> ({{ $s['value'] }}){{ $loop->last ? '.' : ' and ' }}@endforeach
                        @if ($basis['basis_truncated'])
                            <span class="muted">The remaining records were not examined, so this is a sample, not a guarantee.</span>
                        @endif
                    @elseif ($matched)
                        @php
                            $keyCounts = array_diff_key($basis['by_key'], ['new' => 1]);
                            $sampled = $total > count($basis['links']);
                        @endphp
                        @if (count($keyCounts) === 1 && ! $sampled)
                            because they all have
                            <b>{{ $sharedLabels[array_key_first($keyCounts)] ?? $why[array_key_first($keyCounts)] ?? array_key_first($keyCounts) }}</b>.
                        @else
                            {{ $sampled ? '— of the first ' . count($basis['links']) . ' records shown,' : '—' }}
                            @foreach ($keyCounts as $k => $n)
                                <b>{{ $n }}</b> have {{ $sharedLabels[$k] ?? $why[$k] ?? $k }}{{ $loop->last ? '.' : ',' }}
                            @endforeach
                        @endif
                    @elseif ($basis['derive_skipped'])
                        by a later clean-up step. This profile is too large to work out what they share
                        ({{ number_format($total) }} records) — it is one of the known test-data pile-ups.
                    @else
                        by a later clean-up step, and no shared ID was found in the records themselves.
                        Treat this grouping with caution.
                    @endif
                </p>

                @if ($matched)
                    <p>
                        Confidence: <b>{{ $certWord }}</b>@if ($certWhy) — {{ $certWhy }}@endif.
                    </p>
                @endif
            @endif

            @if ($basis['conflicts'])
                {{-- A shared key is why the records merged; a key the members
                     disagree on is why they should not have. Two people who both
                     have an NPI and do not have the same one are not one person. --}}
                <p>
                    <span class="badge excl">Conflicting details</span>
                    {{-- Member counts here come from the same sample as the basis, so
                         when it was truncated the counts are a lower bound. Reporting
                         "2 members disagree" as fact was wrong on identity 3, where the
                         sample saw 2 of 1,038 members carrying 14 distinct NPIs. --}}
                    @if ($basis['basis_truncated'])
                        Among the records checked, some disagree on
                    @else
                        Records inside this profile disagree on
                    @endif
                    @foreach ($basis['conflicts'] as $label => $c)<b>{{ $sharedLabels[$label] ?? $label }}</b> ({{ implode(' vs ', $c['values']) }}){{ $loop->last ? '.' : ', ' }}@endforeach
                    That is a sign this grouping is wrong — see the
                    <a href="{{ route('quality') }}">data quality page</a>.
                    @if ($basis['basis_truncated'])
                        <span class="muted">Counts are from a sample, so treat them as a minimum.</span>
                    @endif
                </p>
            @endif

            @if ($basis['needs_review'] || $basis['pinned'])
                <p>
                    @if ($basis['needs_review'])
                        <span class="badge excl">{{ $basis['needs_review'] }} record(s) waiting for a person to review</span>
                    @endif
                    @if ($basis['pinned'])
                        <span class="badge ok">{{ $basis['pinned'] }} record(s) locked in place by a person</span>
                    @endif
                </p>
            @endif
        </div>

        @if ($basis['links'])
            <table class="kv" style="margin-top:12px;">
                <tr>
                    <th style="width:auto;">Employee record</th>
                    <th style="width:auto;">Account</th>
                    <th style="width:auto;">Why it is in this profile</th>
                    <th style="width:auto;">Confidence</th>
                </tr>
                @foreach ($basis['links'] as $l)
                    @php [$w, ] = $l->match_key === 'new' ? ['—', ''] : $certainty($l->match_score === null ? null : (float) $l->match_score); @endphp
                    <tr>
                        <td>Employee #{{ $l->source_id }}</td>
                        <td>{{ $l->account_id ?: '—' }}</td>
                        <td>{{ $why[$l->match_key] ?? $l->match_key ?? '—' }}</td>
                        <td>{{ $w }}</td>
                    </tr>
                @endforeach
            </table>
            @if ($total > count($basis['links']))
                <p class="sub" style="margin-top:6px;">
                    Showing the first {{ count($basis['links']) }} of {{ number_format($total) }} records.
                </p>
            @endif
        @endif

        @if ($basis['audit'])
            <details style="margin-top:12px;">
                <summary>Where each detail on this profile came from</summary>
                <table class="kv" style="margin-top:8px;">
                    <tr>
                        <th style="width:auto;">Detail</th>
                        <th style="width:auto;">Value used</th>
                        <th style="width:auto;">Why this value won</th>
                    </tr>
                    @foreach ($basis['audit'] as $a)
                        @php
                            $attr = [
                                'canonical_first' => 'First name', 'canonical_middle' => 'Middle name',
                                'canonical_last' => 'Last name', 'canonical_suffix' => 'Suffix',
                                'canonical_dob' => 'Date of birth', 'npi' => 'NPI',
                                'ssn_hash' => 'Social Security number', 'dea_number' => 'DEA number',
                                'upin' => 'UPIN',
                            ][$a->attribute_name] ?? ucfirst(str_replace('_', ' ', $a->attribute_name));
                            $rule = preg_match('/authority\[(.+?)\]/', (string) $a->rule_applied, $m)
                                ? 'Came from the most trusted source (' . $m[1] . '), newest value'
                                : ($a->rule_applied ?: '—');
                            // Print no part of the ssn_hash. It is sha512(ssn + a key
                            // shared with CAMI) over a 9-digit keyspace (~2^30), so a
                            // prefix is not a redaction: 10 hex characters are 40 bits,
                            // far more than enough to pin the one matching SSN and
                            // recover it by brute force once the key is known. It is
                            // also a stable cross-record identifier on its own. The API
                            // withholds the field outright (IdentityProfileResource);
                            // this table only needs to say that a value survived.
                            $value = $a->surviving_value ?: null;
                            if ($value !== null && $a->attribute_name === 'ssn_hash') {
                                $value = 'present (not shown)';
                            }
                        @endphp
                        <tr>
                            <td>{{ $attr }}</td>
                            <td>{{ $value ?: '—' }}</td>
                            <td>{{ $rule }}</td>
                        </tr>
                    @endforeach
                </table>
            </details>
        @endif

        @if ($basis['links'])
            <details style="margin-top:8px;">
                <summary>Technical detail</summary>
                <table class="kv" style="margin-top:8px;">
                    <tr>
                        <th style="width:auto;">Source record</th>
                        <th style="width:auto;">match_key</th>
                        <th style="width:auto;">method</th>
                        <th style="width:auto;">score</th>
                        <th style="width:auto;">state</th>
                        <th style="width:auto;">linked_at</th>
                    </tr>
                    @foreach ($basis['links'] as $l)
                        <tr>
                            <td>{{ $l->system_code ?? '?' }} · {{ $l->source_table }} #{{ $l->source_id }}</td>
                            <td>{{ $l->match_key ?? '—' }}</td>
                            <td>{{ $l->match_method }}</td>
                            <td>{{ $l->match_score !== null ? number_format((float) $l->match_score, 4) : '—' }}</td>
                            <td>{{ $l->match_state }}{{ $l->is_pinned ? ' · pinned' : '' }}</td>
                            <td>{{ $l->linked_at ?: '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            </details>
        @endif
    @endif
</div>

@php
    // Rendered as real tables below, so they are skipped by the raw-JSON loop.
    $rendered = ['identifiers', 'credentials', 'exclusions', 'licenses', 'accounts', 'source_records'];
    $asRows = fn ($v) => is_array($v) ? $v : (is_string($v) ? (json_decode($v, true) ?: []) : []);
    $idents = $asRows($r->identifiers ?? null);
    $creds = $asRows($r->credentials ?? null);
    $excls = $asRows($r->exclusions ?? null);
    $lics = $asRows($r->licenses ?? null);
    $accts = $asRows($r->accounts ?? null);
    $srcs = $asRows($r->source_records ?? null);
    $linkWords = ['confirmed' => 'Confirmed', 'candidate' => 'Possible match', 'rejected' => 'Rejected'];
    $idTypeWords = ['dea' => 'DEA number', 'mmis' => 'MMIS number'];
    $rowCap = 100;
@endphp

@if ($idents)
    <div class="detail-section">
        <div class="detail-section-title">Other ID numbers <span class="muted">({{ count($idents) }})</span></div>
        <table class="kv">
            <tr><th style="width:auto;">Type</th><th style="width:auto;">Number</th></tr>
            @foreach ($idents as $i)
                <tr>
                    <td>{{ $idTypeWords[$i['type'] ?? ''] ?? strtoupper($i['type'] ?? '—') }}</td>
                    <td>{{ $i['value'] ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

@if ($lics)
    <div class="detail-section">
        <div class="detail-section-title">Licenses <span class="muted">({{ count($lics) }})</span></div>
        <table class="kv">
            <tr>
                <th style="width:auto;">License number</th>
                <th style="width:auto;">Type</th>
                <th style="width:auto;">Issuing board</th>
                <th style="width:auto;">State</th>
                <th style="width:auto;">Verified</th>
            </tr>
            @foreach (array_slice($lics, 0, $rowCap) as $l)
                <tr>
                    <td>{{ $l['number'] ?? '—' }}</td>
                    <td>{{ $l['type'] ?: '—' }}</td>
                    <td>{{ $l['board'] ? strtoupper($l['board']) : ($l['registry'] ? strtoupper($l['registry']) : '—') }}</td>
                    <td>{{ $l['state'] ?: '—' }}</td>
                    <td>
                        @if ($l['verified'] ?? false)
                            <span class="badge ok">Verified</span>
                        @else
                            <span class="muted">not verified</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        @if (count($lics) > $rowCap)
            <p class="sub" style="margin-top:6px;">Showing {{ $rowCap }} of {{ count($lics) }} licenses.</p>
        @endif
    </div>
@endif

@if ($creds)
    @php
        // Two kinds of non-answer are left out: checks the registry never
        // returned a status for, and "no cert # match" (the registry simply had
        // no record of that licence number). Neither tells a reviewer anything.
        $isNoCert = fn ($c) => stripos((string) ($c['status'] ?? ''), 'no cert') !== false;
        $noStatus = count(array_filter($creds, fn ($c) => empty($c['status'])));
        $noCert = count(array_filter($creds, $isNoCert));
        $creds = array_values(array_filter(
            $creds,
            fn ($c) => ! empty($c['status']) && ! $isNoCert($c),
        ));
        // Newest/current first — that is the row a reviewer cares about.
        usort($creds, fn ($a, $b) => ($b['current'] ?? false) <=> ($a['current'] ?? false)
            ?: strcmp((string) ($a['registry'] ?? ''), (string) ($b['registry'] ?? '')));
        $credShown = array_slice($creds, 0, $rowCap);
    @endphp
    <div class="detail-section">
        <div class="detail-section-title">Credential checks <span class="muted">({{ count($creds) }})</span></div>
        @if ($credShown)
            <table class="kv">
                <tr>
                    <th style="width:auto;">Registry</th>
                    <th style="width:auto;">Result</th>
                    <th style="width:auto;">Most recent</th>
                    <th style="width:auto;">Link</th>
                    <th style="width:auto;">Match ID</th>
                    <th style="width:auto;"></th>
                </tr>
                @foreach ($credShown as $c)
                    @php $status = $c['status']; @endphp
                    <tr>
                        <td>{{ strtoupper($c['registry'] ?? '—') }}</td>
                        <td>
                            <span class="badge {{ stripos($status, 'invalid') === 0 ? 'excl' : 'ok' }}">{{ $status }}</span>
                        </td>
                        <td>{{ ($c['current'] ?? false) ? 'Yes' : '' }}</td>
                        <td>{{ $linkWords[$c['link_state'] ?? ''] ?? ($c['link_state'] ?? '—') }}</td>
                        <td>{{ $c['credential_match_id'] ?? '—' }}</td>
                        <td>
                            @if (! empty($c['credential_match_id']))
                                <button type="button" class="btn-mini js-match"
                                        data-kind="credential" data-id="{{ $c['credential_match_id'] }}"
                                        data-cols="6">Match data</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @else
            <p class="sub">No completed credential checks for this person.</p>
        @endif
        <p class="sub" style="margin-top:6px;">
            @if (count($creds) > count($credShown))
                Showing {{ count($credShown) }} of {{ count($creds) }} checks.
            @endif
            @php
                $hidden = array_filter([
                    $noStatus ? number_format($noStatus) . ' with no result from the registry' : null,
                    $noCert ? number_format($noCert) . ' where the registry had no matching certificate number' : null,
                ]);
            @endphp
            @if ($hidden)
                <span class="muted">Not listed: {{ implode(', ', $hidden) }}.</span>
            @endif
        </p>
    </div>
@endif

@if ($excls)
    @php $exclShown = array_slice($excls, 0, $rowCap); @endphp
    <div class="detail-section">
        <div class="detail-section-title">Exclusion list hits <span class="muted">({{ count($excls) }})</span></div>
        <table class="kv">
            <tr>
                <th style="width:auto;">Exclusion list</th>
                <th style="width:auto;">Matched on</th>
                <th style="width:auto;">Status</th>
                <th style="width:auto;">Match ID</th>
                <th style="width:auto;"></th>
            </tr>
            @foreach ($exclShown as $x)
                @php
                    $on = array_keys(array_filter([
                        'Social Security number' => $x['is_ssn_match'] ?? false,
                        'NPI' => $x['is_npi_match'] ?? false,
                        'Name' => $x['is_canonical_name_match'] ?? false,
                        'License number' => $x['is_license_number_match'] ?? false,
                    ]));
                @endphp
                <tr>
                    <td>{{ strtoupper($x['registry'] ?? '—') }}</td>
                    <td>
                        @forelse ($on as $o)
                            <span class="basis-chip">{{ $o }}</span>
                        @empty
                            <span class="muted">not recorded</span>
                        @endforelse
                    </td>
                    <td>{{ $linkWords[$x['link_state'] ?? ''] ?? ($x['link_state'] ?? '—') }}</td>
                    <td>{{ $x['match_id'] ?? '—' }}</td>
                    <td>
                        @if (! empty($x['match_id']))
                            <button type="button" class="btn-mini js-match"
                                    data-kind="exclusion" data-id="{{ $x['match_id'] }}"
                                    data-cols="5">Match data</button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        @if (count($excls) > count($exclShown))
            <p class="sub" style="margin-top:6px;">Showing {{ count($exclShown) }} of {{ count($excls) }} hits.</p>
        @endif
        <p class="sub" style="margin-top:6px;">
            A hit is a <b>possible</b> match against a published exclusion list — it still needs a person to confirm or clear it.
        </p>
    </div>
@endif

@if ($accts || $srcs)
    @php
        // Source records carry the account, so the account list can say how many
        // employee records each customer contributed to this person.
        $perAccount = [];
        foreach ($srcs as $s) {
            $a = $s['account_id'] ?? null;
            $perAccount[$a] = ($perAccount[$a] ?? 0) + 1;
        }
    @endphp

    @if ($accts)
        <div class="detail-section">
            <div class="detail-section-title">Accounts this person appears in <span class="muted">({{ count($accts) }})</span></div>
            <table class="kv">
                <tr>
                    <th style="width:auto;">Account</th>
                    <th style="width:auto;">Employee records from this account</th>
                </tr>
                @foreach ($accts as $a)
                    @php $a = is_array($a) ? ($a['account_id'] ?? null) : $a; @endphp
                    <tr>
                        <td>Account #{{ $a ?? '—' }}</td>
                        <td>{{ $perAccount[$a] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($srcs)
        <div class="detail-section">
            <div class="detail-section-title">Where this person came from <span class="muted">({{ count($srcs) }})</span></div>
            <table class="kv">
                <tr>
                    <th style="width:auto;">Employee record</th>
                    <th style="width:auto;">Account</th>
                    <th style="width:auto;">Source system</th>
                </tr>
                @foreach (array_slice($srcs, 0, $rowCap) as $s)
                    <tr>
                        <td>Employee #{{ $s['source_id'] ?? '—' }}</td>
                        <td>{{ isset($s['account_id']) ? 'Account #'.$s['account_id'] : '—' }}</td>
                        <td>{{ $s['system_code'] ?? '—' }}
                            <span class="muted">({{ $s['source_table'] ?? '—' }})</span></td>
                    </tr>
                @endforeach
            </table>
            @if (count($srcs) > $rowCap)
                <p class="sub" style="margin-top:6px;">
                    Showing {{ $rowCap }} of {{ number_format(count($srcs)) }} records.
                </p>
            @endif
        </div>
    @endif
@endif

@if ($oversized)
    <div class="notice">
        Not loaded — too large to display: @foreach ($oversized as $col => $mb)<b>{{ $col }}</b> ({{ $mb }} MB){{ $loop->last ? '.' : ', ' }}@endforeach
        The counts above are still accurate — only the full lists are held back.
    </div>
@endif

@foreach (array_diff(config('gpcami.profile_json'), $rendered) as $key)
    @php $val = $r->{$key}; @endphp
    @if (! empty($val))
        <div class="detail-section">
            <div class="detail-section-title">{{ ucfirst(str_replace('_', ' ', $key)) }}
                <span class="muted">({{ is_array($val) ? count($val) : 1 }})</span></div>
            <pre class="json">{{ json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif
@endforeach
