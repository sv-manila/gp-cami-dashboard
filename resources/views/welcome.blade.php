@extends('layouts.app')
@section('title', 'Overview & Docs · gp-cami dashboard')

@push('head')
<style>
  .ov-hero{background:linear-gradient(180deg,var(--navy),var(--navy-deep));color:#fff;
    border-radius:var(--radius);padding:32px;position:relative;overflow:hidden;box-shadow:var(--shadow);}
  .ov-hero::after{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;background:var(--gold-grad);}
  .ov-hero h1{color:#fff;font-size:1.9rem;font-weight:800;letter-spacing:-.02em;margin:0;}
  .ov-hero h1 .g{background:var(--gold-grad);-webkit-background-clip:text;background-clip:text;color:transparent;}
  .ov-hero p{color:#cdd8e4;max-width:70ch;margin:10px 0 0;}
  /* in-page section nav */
  .docnav{position:sticky;top:64px;z-index:10;display:flex;flex-wrap:wrap;gap:6px;
    background:var(--bg);padding:12px 0;margin:8px 0 4px;border-bottom:1px solid var(--line);}
  .docnav a{font-size:12px;font-weight:700;color:var(--ink-soft);background:var(--surface);
    border:1px solid var(--line);border-radius:999px;padding:5px 12px;}
  .docnav a:hover{color:#fff;background:var(--orange);border-color:var(--orange);text-decoration:none;}
  section.doc{padding:26px 0;border-bottom:1px solid var(--line);scroll-margin-top:120px;}
  section.doc h2{color:var(--navy);font-size:1.35rem;font-weight:800;margin:0 0 4px;letter-spacing:-.01em;}
  @media (prefers-color-scheme:dark){section.doc h2,.ov-card h3{color:var(--ink);}}
  section.doc > p.lede{color:var(--ink-soft);max-width:80ch;margin:0 0 16px;}
  .eyebrow{font-family:var(--mono);font-size:11px;letter-spacing:.14em;text-transform:uppercase;
    color:var(--orange);font-weight:700;margin:0 0 6px;}
  .pipe{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
  .pstep{position:relative;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    padding:14px 16px;box-shadow:var(--shadow);}
  .pstep .k{font-family:var(--mono);font-size:10px;font-weight:800;letter-spacing:.12em;color:var(--orange);}
  .pstep h4{margin:4px 0 4px;font-size:.95rem;color:var(--navy);}
  .pstep p{margin:0;font-size:.82rem;color:var(--ink-soft);}
  @media (prefers-color-scheme:dark){.pstep h4{color:var(--ink);}}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
  .spec{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    padding:16px 18px;box-shadow:var(--shadow);}
  .spec h4{margin:0 0 8px;color:var(--navy);font-size:.95rem;}
  @media (prefers-color-scheme:dark){.spec h4{color:var(--ink);}}
  .kvrow{display:flex;justify-content:space-between;gap:12px;padding:4px 0;border-top:1px solid var(--line);
    font-size:.85rem;}
  .kvrow:first-of-type{border-top:none;}
  .kvrow .v{font-family:var(--mono);color:var(--ink-soft);white-space:nowrap;}
  pre.code{background:var(--surface-2);border:1px solid var(--line);border-radius:8px;padding:14px;
    overflow:auto;font-family:var(--mono);font-size:12px;line-height:1.55;margin:10px 0;}
  .method{display:inline-block;font-family:var(--mono);font-size:11px;font-weight:800;color:#fff;
    background:var(--ok);border-radius:5px;padding:2px 8px;margin-right:8px;}
  table.doc-t{margin-top:8px;}
  table.doc-t td:first-child code{white-space:nowrap;}
  .flow{font-family:var(--mono);font-size:12px;color:var(--ink-soft);background:var(--surface-2);
    border:1px solid var(--line);border-radius:8px;padding:10px 14px;overflow:auto;}
  @media (max-width:820px){.pipe{grid-template-columns:1fr 1fr;}.grid2{grid-template-columns:1fr;}}
</style>
@endpush

@section('content')
  <div class="ov-hero">
    <h1>Golden <span class="g">Profile</span> — overview &amp; docs</h1>
    <p>gp-cami resolves every StreamlineVerify source record into one trusted "golden" identity per real
    person, rolls up their licenses / credentials / exclusions, and serves a denormalized profile.
    This is the working reference for its schema, pipeline, resolution logic, commands and API.</p>
    <a href="{{ route('dashboard') }}" class="ov-cta" style="display:inline-block;background:var(--orange);color:#fff;font-weight:700;border-radius:8px;padding:10px 20px;margin-top:14px;">Open dashboard →</a>
  </div>

  <nav class="docnav">
    <a href="#pipeline">Pipeline</a>
    <a href="#resolution">Resolution logic</a>
    <a href="#survivorship">Survivorship</a>
    <a href="#schema">Schema</a>
    <a href="#commands">Commands</a>
    <a href="#api">API</a>
    <a href="#ssn">SSN &amp; security</a>
  </nav>

  {{-- ---------------- PIPELINE ---------------- --}}
  <section class="doc" id="pipeline">
    <p class="eyebrow">Process</p>
    <h2>The pipeline</h2>
    <p class="lede">Two modes share the same per-record logic. <b>Mode&nbsp;1 (backfill)</b> is the one-time
    backlog; <b>Mode&nbsp;2 (sync)</b> is the incremental catch-up driven by a watermark. Backfill is
    set-based and runs as one command:</p>
    <div class="flow">stage&nbsp;→&nbsp;index&nbsp;→&nbsp;resolve&nbsp;→&nbsp;enrich&nbsp;→&nbsp;dedup&nbsp;→&nbsp;rollup&nbsp;→&nbsp;finalize</div>
    <div class="pipe" style="margin-top:14px;">
      <div class="pstep"><div class="k">1 · STAGE</div><h4>Bulk load</h4><p>Copy <code>employees</code> (+ their alt-fields and <code>employee_additional_info</code>) into hub-local <code>stg_person</code> and mirror <code>credential_matches</code>/<code>matches</code>. Parallel, resumable via per-stripe checkpoints.</p></div>
      <div class="pstep"><div class="k">2 · INDEX</div><h4>Index staged keys</h4><p>Add indexes on the tier keys after load (kept off during bulk insert) so resolution is index lookups, not full scans.</p></div>
      <div class="pstep"><div class="k">3 · RESOLVE</div><h4>Deterministic tiers</h4><p>One create+link pair per key tier, in confidence order; each staged row binds to an identity or seeds a new one.</p></div>
      <div class="pstep"><div class="k">4 · ENRICH</div><h4>Licenses / addresses / identifiers</h4><p>Populate <code>gp_license</code>, <code>gp_address</code>, <code>gp_identity_identifier</code> from the staged children.</p></div>
      <div class="pstep"><div class="k">5 · DEDUP</div><h4>Merge duplicates</h4><p>Fold identities that share a deterministic key (incl. DEA/MMIS) to a fixed point; row-locked, shardable.</p></div>
      <div class="pstep"><div class="k">6 · ROLLUP</div><h4>Credentials / exclusions</h4><p>Set-based joins from the source mirrors into <code>gp_identity_credential</code> (confirmed) and <code>gp_identity_exclusion</code> (candidate).</p></div>
      <div class="pstep"><div class="k">7 · FINALIZE</div><h4>Survivorship + materialize</h4><p>Pick per-field winners, then rebuild the wide <code>gp_identity_profile</code> row. Sharded by identity_id.</p></div>
      <div class="pstep"><div class="k">MODE 2 · SYNC</div><h4>Incremental</h4><p><code>gp:sync</code> processes only rows changed since the <code>gp_watermark</code> high-water mark; same resolve→finalize logic per record.</p></div>
    </div>
  </section>

  {{-- ---------------- RESOLUTION ---------------- --}}
  <section class="doc" id="resolution">
    <p class="eyebrow">Profiling logic</p>
    <h2>Resolution — how records match</h2>
    <p class="lede">Two-pass. <b>Pass A (deterministic)</b> binds on exact high-precision keys in confidence
    order; the first hit wins, a miss seeds a new identity. <b>Pass B (probabilistic)</b> scores the
    residual with weighted similarity and either auto-merges, flags for steward review, or defers to a new
    identity — recall-first for compliance, precision-first for identity.</p>
    <div class="grid2">
      <div class="spec">
        <h4>Pass A — deterministic keys (confidence)</h4>
        <div class="kvrow"><span>SSN hash</span><span class="v">0.99</span></div>
        <div class="kvrow"><span>NPI</span><span class="v">0.99</span></div>
        <div class="kvrow"><span>DEA number</span><span class="v">0.99</span></div>
        <div class="kvrow"><span>UPIN</span><span class="v">0.99</span></div>
        <div class="kvrow"><span>License # + cert. state</span><span class="v">0.99</span></div>
        <div class="kvrow"><span>Name + DOB</span><span class="v">0.95</span></div>
        <p style="margin:10px 0 0;font-size:.8rem;color:var(--ink-faint);">DEA &amp; MMIS are multi-valued (from <code>employee_additional_info</code>) and merge via dedup.</p>
      </div>
      <div class="spec">
        <h4>Pass B — probabilistic (Jaro-Winkler)</h4>
        <div class="kvrow"><span>Name (over all aliases)</span><span class="v">0.45</span></div>
        <div class="kvrow"><span>DOB</span><span class="v">0.20</span></div>
        <div class="kvrow"><span>Address (any-vs-any)</span><span class="v">0.15</span></div>
        <div class="kvrow"><span>Provider type</span><span class="v">0.08</span></div>
        <div class="kvrow"><span>Shared exclusion</span><span class="v">0.07</span></div>
        <div class="kvrow"><span>ZIP</span><span class="v">0.05</span></div>
        <p style="margin:10px 0 0;font-size:.8rem;color:var(--ink-faint);">Auto-merge ≥ <b>0.92</b>; review band <b>0.75–0.92</b>; below → new. Hard-no: conflicting DOB, or two different valid NPIs. Blocks capped at 2000 (oversized flagged, never truncated).</p>
      </div>
    </div>
  </section>

  {{-- ---------------- SURVIVORSHIP ---------------- --}}
  <section class="doc" id="survivorship">
    <p class="eyebrow">Profiling logic</p>
    <h2>Survivorship — which value wins</h2>
    <p class="lede">Per field, the winning source is chosen by an authority order (below), with recency
    breaking ties and internal "verified" data decaying after 365 days. Every decision is recorded in
    <code>gp_survivorship_audit</code>, and each contributing value in <code>gp_attribute</code> (with an
    <code>is_canonical</code> flag).</p>
    <div class="grid2">
      <div class="spec"><h4>Field authority (best → worst)</h4>
        <div class="kvrow"><span>Identity</span><span class="v">verified · nppes · streamline · state · scraped</span></div>
        <div class="kvrow"><span>License</span><span class="v">state · nppes · scraped · streamline</span></div>
        <div class="kvrow"><span>Address</span><span class="v">nppes · state · streamline · scraped</span></div>
        <div class="kvrow"><span>Exclusion</span><span class="v">leie · sam · state · streamline</span></div>
      </div>
      <div class="spec"><h4>Status severity (worst wins for compliance)</h4>
        <div class="kvrow"><span>revoked / excluded</span><span class="v">5</span></div>
        <div class="kvrow"><span>suspended</span><span class="v">4</span></div>
        <div class="kvrow"><span>lapsed</span><span class="v">3</span></div>
        <div class="kvrow"><span>expired</span><span class="v">2</span></div>
        <div class="kvrow"><span>active</span><span class="v">1</span></div>
        <p style="margin:10px 0 0;font-size:.8rem;color:var(--ink-faint);">Tracks: identity = precision-first, compliance = recall-first. Exclusion links default to <code>candidate</code>.</p>
      </div>
    </div>
  </section>

  {{-- ---------------- SCHEMA ---------------- --}}
  <section class="doc" id="schema">
    <p class="eyebrow">Data model</p>
    <h2>Schema</h2>
    <p class="lede">A relational graph on the <code>golden_profile</code> hub: one identity node, its
    evidence and one-to-many collections, a denormalized read model, plus staging and source-mirror tables.</p>
    <table class="doc-t">
      <tr><th>Table</th><th>Holds</th></tr>
      <tr><td><code>gp_identity</code></td><td>One resolved real person; canonical name/DOB + keys (ssn_hash, npi, upin, dea), status, confidence.</td></tr>
      <tr><td><code>gp_identity_profile</code></td><td>Denormalized read model — one wide row per identity (what the dashboard &amp; API serve).</td></tr>
      <tr><td><code>gp_source_link</code></td><td>Each source row bound to an identity; match_method / key / score / state, pinned flag.</td></tr>
      <tr><td><code>gp_identity_identifier</code></td><td>Multi-valued identifiers (DEA, MMIS) — match keys.</td></tr>
      <tr><td><code>gp_license</code> / <code>gp_address</code></td><td>One-to-many licenses (number/state/board) and addresses.</td></tr>
      <tr><td><code>gp_identity_credential</code></td><td>credential_matches rolled up to an identity (confirmed links).</td></tr>
      <tr><td><code>gp_identity_exclusion</code></td><td>exclusion matches rolled up (candidate links).</td></tr>
      <tr><td><code>gp_board_action</code></td><td>Disciplinary / board actions.</td></tr>
      <tr><td><code>gp_attribute</code></td><td>Per-field provenance for every contributing value (is_canonical).</td></tr>
      <tr><td><code>gp_survivorship_audit</code></td><td>The winning value + rule applied, per field.</td></tr>
      <tr><td><code>gp_identity_resolution</code> / <code>gp_resolution_log</code></td><td>Steward decisions and the merge/relink audit trail.</td></tr>
      <tr><td><code>gp_edge</code></td><td>Identity graph edges.</td></tr>
      <tr><td><code>gp_watermark</code></td><td>Sync high-water mark + backfill staging checkpoints.</td></tr>
      <tr><td><code>gp_source_system</code></td><td>Registry of source systems + reliability rank.</td></tr>
      <tr><td><code>stg_person</code> (+ <code>_alias</code>/<code>_address</code>/<code>_license</code>/<code>_identifier</code>)</td><td>Canonical staging — source mapped into one shape before resolution.</td></tr>
      <tr><td><code>src_credential_match</code> / <code>src_match</code> / <code>src_exclusion_record</code></td><td>Hub-local mirrors of the source tables so rollups run as in-DB joins.</td></tr>
    </table>
  </section>

  {{-- ---------------- COMMANDS ---------------- --}}
  <section class="doc" id="commands">
    <p class="eyebrow">Operations</p>
    <h2>Commands</h2>
    <table class="doc-t">
      <tr><th>Command</th><th>What it does</th></tr>
      <tr><td><code>gp:backfill</code></td><td>Mode 1 — the whole backlog in one set-based command (stage→index→resolve→enrich→dedup→rollup→finalize). Parallel staging + sharded finalize, resumable.</td></tr>
      <tr><td><code>gp:sync</code></td><td>Mode 2 — incremental; only rows changed since the watermark. Idempotent re-runs.</td></tr>
      <tr><td><code>gp:rebuild-profile</code></td><td>Re-materialize <code>gp_identity_profile</code> for one identity or all.</td></tr>
    </table>
    <p class="lede" style="margin-top:14px;">Backfill options:</p>
    <div class="spec">
      <div class="kvrow"><span><code>--from-id</code> / <code>--to-id</code></span><span class="v">bound the source id range (partition / sanity run)</span></div>
      <div class="kvrow"><span><code>--workers</code></span><span class="v">parallel degree for stage + finalize (default 16)</span></div>
      <div class="kvrow"><span><code>--chunk</code></span><span class="v">staging batch size (default 5000)</span></div>
      <div class="kvrow"><span><code>--restart</code></span><span class="v">clear staging checkpoints, start fresh</span></div>
    </div>
    <pre class="code"># full backlog
php artisan gp:backfill

# bounded / sanity slice
php artisan gp:backfill --from-id=1 --to-id=50000 --workers=8

# incremental thereafter
php artisan gp:sync</pre>
  </section>

  {{-- ---------------- API ---------------- --}}
  <section class="doc" id="api">
    <p class="eyebrow">Integration</p>
    <h2>API (v1)</h2>
    <p class="lede">JSON over HTTP, prefix <code>/api/v1</code>. Responses expose <code>ssn_last_four</code> only — never the SSN.</p>

    <div class="spec" style="margin-bottom:16px;">
      <h4><span class="method">POST</span><code>/api/v1/identity-search</code></h4>
      <p style="font-size:.85rem;color:var(--ink-soft);margin:0 0 8px;">Find golden identities by name (last name required).</p>
      <pre class="code">// request
{ "last_name": "Mammone", "first_name": "John", "per_page": 25 }

// response
{ "data": [ { "identity_uuid": "...", "first_name": "...", "last_name": "...",
              "npi": ..., "identifiers": [{"type":"dea","value":"..."}],
              "has_active_exclusion": false, ... } ],
  "meta": { "total": 3, "page": 1, "per_page": 25, "last_page": 1 } }</pre>
    </div>

    <div class="spec">
      <h4><span class="method">POST</span><code>/api/v1/credential-search</code></h4>
      <p style="font-size:.85rem;color:var(--ink-soft);margin:0 0 8px;">Resolve a person (name/DOB/SSN/license) and return their credential status for a registry.</p>
      <pre class="code">// request
{ "last_name": "...", "first_name": "...", "dob": "1980-05-01",
  "ssn": "…", "license_number": "…", "registry": "CA-RN" }

// response
{ "identity": { "identity_id": 42, "identity_uuid": "...",
                "first_name": "...", "last_name": "...", "ssn_last_four": "6789" },
  "match": { ...qualifying credential... },
  "prior_resolution": { ...steward decision, if any... } }</pre>
      <p style="font-size:.8rem;color:var(--ink-faint);margin:8px 0 0;">Qualifying status codes: 20,30,40,45,65,70,80,85,90 · excluded: 0,10,50,60,100.</p>
    </div>
  </section>

  {{-- ---------------- SSN ---------------- --}}
  <section class="doc" id="ssn" style="border-bottom:none;">
    <p class="eyebrow">Security</p>
    <h2>SSN handling</h2>
    <p class="lede">SSNs are matched on a hash, never in plaintext: <code>sha512(ssn + plaintext_key)</code>,
    parity with <code>streamline_local.social_security_num</code>. The hash is the join key; the stored
    value is encrypted; API responses return <code>ssn_last_four</code> only. Identity resolution uses the
    hash so two records with the same SSN merge without either side handling the raw number.</p>
  </section>
@endsection
