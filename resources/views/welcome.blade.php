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
  /* ---- "Start here" orientation section ---------------------------------
     Everything below the fold on this page is reference for people who already
     know the domain. This section is the part that assumes nothing, so it leans
     on diagrams rather than prose: the shape of the problem is much easier to
     see than to read.

     The SVGs are styled through classes rather than fill="" attributes so the
     one drawing serves both themes -- the palette below is redefined under
     prefers-color-scheme:dark and the diagrams follow it. */
  .dgm{width:100%;height:auto;display:block;}
  .dgm-wrap{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    padding:18px;box-shadow:var(--shadow);margin:14px 0 0;overflow-x:auto;}
  @media (max-width:760px){.dgm{min-width:660px;}}
  .dgm-cap{font-size:.8rem;color:var(--ink-faint);margin:10px 2px 0;}
  /* diagram palette */
  .d-card{fill:var(--surface-2);stroke:var(--line);}
  .d-card-src{fill:var(--surface-2);stroke:var(--line);stroke-dasharray:3 3;}
  .d-gold{fill:var(--surface);stroke:var(--orange);stroke-width:2;}
  .d-hub{fill:var(--surface-2);stroke:var(--navy);}
  @media (prefers-color-scheme:dark){.d-hub{stroke:var(--link);}}
  .d-t{fill:var(--ink);font-family:var(--sans);font-size:15px;}
  .d-t-sm{fill:var(--ink-soft);font-family:var(--sans);font-size:13px;}
  .d-t-mono{fill:var(--ink-soft);font-family:var(--mono);font-size:12.5px;}
  .d-t-key{fill:var(--orange);font-family:var(--mono);font-size:12.5px;font-weight:700;}
  .d-t-hd{fill:var(--ink-faint);font-family:var(--mono);font-size:11.5px;
    letter-spacing:.12em;text-transform:uppercase;font-weight:700;}
  .d-arrow{stroke:var(--ink-faint);stroke-width:1.6;fill:none;}
  .d-arrowhead{fill:var(--ink-faint);}
  .d-accent{fill:var(--orange);}
  .d-rule{stroke:var(--line);stroke-width:1;}

  /* three pillars */
  .pillars{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:14px;}
  .pillar{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    padding:14px 16px;box-shadow:var(--shadow);border-top:3px solid var(--orange);}
  .pillar h4{margin:0 0 4px;font-size:.95rem;color:var(--navy);}
  @media (prefers-color-scheme:dark){.pillar h4{color:var(--ink);}}
  .pillar p{margin:0;font-size:.82rem;color:var(--ink-soft);}
  @media (max-width:760px){.pillars{grid-template-columns:1fr;}}

  /* glossary: a lookup grid, not a paragraph */
  .gloss{display:grid;grid-template-columns:repeat(auto-fill,minmax(232px,1fr));gap:10px;margin-top:14px;}
  .gterm{background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--gold);
    border-radius:8px;padding:10px 12px;}
  .gterm b{display:block;font-family:var(--mono);font-size:.82rem;color:var(--navy);margin-bottom:2px;}
  @media (prefers-color-scheme:dark){.gterm b{color:var(--ink);}}
  .gterm span{font-size:.79rem;color:var(--ink-soft);line-height:1.45;}

  /* at-a-glance counts */
  .glance{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:14px;}
  .gstat{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    padding:12px 14px;box-shadow:var(--shadow);}
  .gstat .n{font-size:1.25rem;font-weight:800;color:var(--navy);font-variant-numeric:tabular-nums;
    letter-spacing:-.02em;}
  @media (prefers-color-scheme:dark){.gstat .n{color:var(--ink);}}
  .gstat .l{font-size:.72rem;color:var(--ink-faint);text-transform:uppercase;letter-spacing:.7px;
    font-weight:700;margin-top:2px;}
  /* one card per command: name, what it does, then its options */
  .cmd{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    padding:16px 18px;box-shadow:var(--shadow);margin-bottom:14px;}
  .cmd h4{margin:0 0 7px;font-family:var(--mono);font-size:.92rem;color:var(--navy);letter-spacing:-.01em;}
  @media (prefers-color-scheme:dark){.cmd h4{color:var(--ink);}}
  .cmd > p{margin:0;font-size:.85rem;color:var(--ink-soft);max-width:86ch;}
  .cmd .opts{margin-top:12px;}
  .optrow{display:grid;grid-template-columns:210px 1fr;gap:14px;padding:6px 0;
    border-top:1px solid var(--line);font-size:.82rem;}
  .optrow:first-child{border-top:none;}
  .optrow > code{white-space:nowrap;justify-self:start;}
  .optrow > span{color:var(--ink-soft);}
  /* the orchestrator's own sub-worker flags — documented so they are not mistaken
     for knobs, greyed so they are not mistaken for knobs worth turning */
  .optrow.internal > span,.optrow.internal > code{color:var(--ink-faint);}
  @media (max-width:640px){.optrow{grid-template-columns:1fr;gap:3px;}}
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
    <a href="#start">Start here</a>
    <a href="#pipeline">Pipeline</a>
    <a href="#resolution">Resolution logic</a>
    <a href="#survivorship">Survivorship</a>
    <a href="#schema">Schema</a>
    <a href="#commands">Commands</a>
    <a href="#api">API</a>
    <a href="#playground">Try it</a>
    <a href="#ssn">SSN &amp; security</a>
  </nav>

  {{-- ---------------- START HERE ---------------- --}}
  <section class="doc" id="start">
    <p class="eyebrow">Start here</p>
    <h2>One person, one identity</h2>
    <p class="lede">The same nurse turns up again and again across <code>streamline_local</code> &mdash;
    under different clients, on different lists, a maiden name here, a typo there, a licence that moved
    state. Each row is a separate record. None of them knows about the others.</p>

    <div class="dgm-wrap">
      <svg class="dgm" viewBox="0 0 880 288" role="img"
           aria-label="Four separate source records for the same nurse — differing in surname, spelling and licence state but sharing an SSN hash and NPI — resolve into one golden identity that carries her licences, credentials and exclusions.">
        <text x="0" y="14" class="d-t-hd">4 source records</text>
        <text x="368" y="14" class="d-t-hd">resolve</text>
        <text x="566" y="14" class="d-t-hd">1 golden identity</text>

        {{-- the four raw rows: same person, four spellings --}}
        <g>
          <rect x="0" y="28" width="300" height="46" rx="7" class="d-card-src"/>
          <text x="14" y="49" class="d-t">Smith, Jane A.</text>
          <text x="14" y="66" class="d-t-mono">acct 41 &middot; NPI 1234567893</text>

          <rect x="0" y="82" width="300" height="46" rx="7" class="d-card-src"/>
          <text x="14" y="103" class="d-t">Doe, Jane</text>
          <text x="14" y="120" class="d-t-mono">acct 77 &middot; maiden name</text>

          <rect x="0" y="136" width="300" height="46" rx="7" class="d-card-src"/>
          <text x="14" y="157" class="d-t">Smtih, Jane</text>
          <text x="14" y="174" class="d-t-mono">acct 41 &middot; typo</text>

          <rect x="0" y="190" width="300" height="46" rx="7" class="d-card-src"/>
          <text x="14" y="211" class="d-t">Smith, Janet</text>
          <text x="14" y="228" class="d-t-mono">acct 12 &middot; licence now TX</text>
        </g>

        {{-- what they share is what binds them --}}
        <text x="0" y="262" class="d-t-sm">Shared between them:</text>
        <text x="132" y="262" class="d-t-key">ssn_hash</text>
        <text x="196" y="262" class="d-t-sm">and</text>
        <text x="226" y="262" class="d-t-key">NPI</text>

        {{-- arrows into the engine --}}
        <path d="M300 51 H 330 Q342 51 342 63 V 120" class="d-arrow"/>
        <path d="M300 105 H 342" class="d-arrow"/>
        <path d="M300 159 H 330 Q342 159 342 147 V 132" class="d-arrow"/>
        <path d="M300 213 H 330 Q342 213 342 201 V 132" class="d-arrow"/>

        {{-- the engine --}}
        <rect x="352" y="96" width="150" height="60" rx="7" class="d-hub"/>
        <text x="427" y="121" class="d-t" text-anchor="middle">Resolution</text>
        <text x="427" y="140" class="d-t-sm" text-anchor="middle">Pass A, then Pass B</text>

        <path d="M502 126 H 552" class="d-arrow"/>
        <polygon points="552,121 564,126 552,131" class="d-arrowhead"/>

        {{-- the one identity that comes out --}}
        <rect x="566" y="28" width="314" height="208" rx="9" class="d-gold"/>
        <text x="584" y="56" class="d-t" style="font-weight:800;">Jane Smith</text>
        <text x="584" y="76" class="d-t-mono">identity_uuid &middot; canonical name + DOB</text>
        <line x1="584" y1="90" x2="862" y2="90" class="d-rule"/>

        <text x="584" y="112" class="d-t-hd">rolled up</text>
        <circle cx="590" cy="132" r="3" class="d-accent"/>
        <text x="602" y="137" class="d-t-sm">2 licences (CA, TX) &middot; 4 aliases</text>
        <circle cx="590" cy="156" r="3" class="d-accent"/>
        <text x="602" y="161" class="d-t-sm">credential matches from every account</text>
        <circle cx="590" cy="180" r="3" class="d-accent"/>
        <text x="602" y="185" class="d-t-sm">exclusion hits, flagged for review</text>
        <circle cx="590" cy="204" r="3" class="d-accent"/>
        <text x="602" y="209" class="d-t-sm">the 4 source rows, still traceable</text>
      </svg>
    </div>
    <p class="dgm-cap">Nothing is thrown away: every source row stays linked to the identity it resolved
    into, so any value on the profile can be traced back to the record it came from.</p>

    <div class="pillars">
      <div class="pillar">
        <h4>Resolve</h4>
        <p>Decide which raw rows are the same human being. Exact keys first, similarity scoring for
        the rest.</p>
      </div>
      <div class="pillar">
        <h4>Enrich</h4>
        <p>Hang everything known about that person off the one identity &mdash; aliases, licences,
        addresses, credentials, exclusions.</p>
      </div>
      <div class="pillar">
        <h4>Reuse</h4>
        <p>A decision a reviewer makes once is remembered, and comes back with the person the next
        time anyone searches &mdash; under any account.</p>
      </div>
    </div>

    <h2 style="margin-top:30px;">Where the pieces live</h2>
    <p class="lede">Two applications. <b>gp-cami</b> builds and serves the hub; <b>gp-cami-dashboard</b>
    &mdash; this app &mdash; only looks at it.</p>

    <div class="dgm-wrap">
      <svg class="dgm" viewBox="0 0 880 250" role="img"
           aria-label="streamline_local is read through a connector into staging; the resolution engine writes the golden_profile hub; gp-cami serves it to CAMI over a REST API, while the read-only gp-cami-dashboard reads the hub directly.">
        {{-- source --}}
        <text x="0" y="14" class="d-t-hd">source &middot; read-only</text>
        <rect x="0" y="26" width="150" height="58" rx="7" class="d-card"/>
        <text x="75" y="50" class="d-t" text-anchor="middle">CAMI</text>
        <text x="75" y="70" class="d-t-mono" text-anchor="middle">streamline_local</text>

        <path d="M150 55 H 196" class="d-arrow"/>
        <polygon points="196,50 208,55 196,60" class="d-arrowhead"/>
        <text x="152" y="44" class="d-t-sm">connector</text>

        {{-- gp-cami --}}
        <rect x="216" y="0" width="404" height="164" rx="10" class="d-hub" fill="none"/>
        <text x="232" y="20" class="d-t-hd">gp-cami &middot; writes the hub</text>

        <rect x="232" y="32" width="122" height="52" rx="7" class="d-card"/>
        <text x="293" y="54" class="d-t-sm" text-anchor="middle">staging</text>
        <text x="293" y="72" class="d-t-mono" text-anchor="middle">stg_person</text>

        <path d="M354 58 H 386" class="d-arrow"/>
        <polygon points="386,53 398,58 386,63" class="d-arrowhead"/>

        <rect x="398" y="32" width="122" height="52" rx="7" class="d-hub"/>
        <text x="459" y="54" class="d-t-sm" text-anchor="middle">resolution</text>
        <text x="459" y="72" class="d-t-sm" text-anchor="middle">engine</text>

        <path d="M459 84 V 104" class="d-arrow"/>
        <polygon points="454,104 459,116 464,104" class="d-arrowhead"/>

        <rect x="336" y="116" width="248" height="36" rx="7" class="d-gold"/>
        <text x="460" y="139" class="d-t-mono" text-anchor="middle">golden_profile hub</text>

        {{-- API out to CAMI --}}
        <path d="M584 134 H 648 Q660 134 660 122 V 74" class="d-arrow"/>
        <polygon points="655,74 660,62 665,74" class="d-arrowhead"/>
        <rect x="596" y="26" width="128" height="36" rx="7" class="d-card"/>
        <text x="660" y="49" class="d-t-mono" text-anchor="middle">REST /api/v1</text>
        <path d="M724 44 H 772" class="d-arrow"/>
        <polygon points="772,39 784,44 772,49" class="d-arrowhead"/>
        <text x="790" y="49" class="d-t-sm">CAMI</text>

        {{-- dashboard --}}
        <path d="M460 152 V 186" class="d-arrow" stroke-dasharray="4 3"/>
        <polygon points="455,186 460,198 465,186" class="d-arrowhead"/>
        <rect x="300" y="198" width="320" height="46" rx="7" class="d-card"/>
        <text x="460" y="220" class="d-t" text-anchor="middle">gp-cami-dashboard</text>
        <text x="460" y="237" class="d-t-sm" text-anchor="middle">this app &middot; reads only, never writes</text>
      </svg>
    </div>
    <p class="dgm-cap">The engine only ever sees staging, never a source&rsquo;s own schema &mdash; so adding
    a second source database later is a connector and a config row, not a rebuild.</p>

    <h2 style="margin-top:30px;">The vocabulary</h2>
    <p class="lede">Terms used throughout the rest of this page.</p>

    <div class="gloss">
      <div class="gterm"><b>CAMI</b><span>The existing Streamline Verify credentialing application, and the
        source of every record here. Its database is <code>streamline_local</code>.</span></div>
      <div class="gterm"><b>golden identity</b><span>One resolved real person, and the record all the
        raw rows for them point at.</span></div>
      <div class="gterm"><b>credential</b><span>A professional licence or registration held by a person
        and checked against an issuing registry.</span></div>
      <div class="gterm"><b>exclusion</b><span>A hit on a list of people barred from working in
        federally funded healthcare. The thing customers are really buying.</span></div>
      <div class="gterm"><b>registry</b><span>The issuing body a credential is verified against &mdash;
        a state licensing board, say.</span></div>
      <div class="gterm"><b>steward</b><span>A person who reviews the matches the engine was not
        confident enough to decide alone. See <a href="{{ route('review') }}">Review</a>.</span></div>
      <div class="gterm"><b>NPI</b><span>National Provider Identifier &mdash; the 10-digit US id for a
        healthcare provider. A strong match key.</span></div>
      <div class="gterm"><b>DEA &middot; UPIN</b><span>Two more provider ids: a Drug Enforcement Administration
        controlled-substance registration, and the retired Unique Physician Identification
        Number.</span></div>
      <div class="gterm"><b>LEIE &middot; SAM</b><span>The two federal exclusion lists: the OIG&rsquo;s List of Excluded
        Individuals and Entities, and the System for Award Management.</span></div>
      <div class="gterm"><b>NPPES</b><span>National Plan and Provider Enumeration System &mdash; the federal registry
        behind NPI numbers. High authority for names and addresses.</span></div>
      <div class="gterm"><b>MMIS</b><span>Medicaid Management Information System &mdash; a state Medicaid provider id.
        Multi-valued: one person can hold several.</span></div>
      <div class="gterm"><b>Jaro-Winkler</b><span>A string similarity measure (0&ndash;1) that rates names alike even
        when spelled differently. Powers Pass&nbsp;B.</span></div>
    </div>

    @if (! empty($glance['counts'] ?? []))
      <h2 style="margin-top:30px;">The hub today</h2>
      <p class="lede">Last recorded {{ $glance['as_of'] }} by <code>gpdash:snapshot</code>. Live numbers
        are on the <a href="{{ route('dashboard') }}">Dashboard</a>.</p>
      <div class="glance">
        @foreach ($glance['counts'] as $label => $c)
          <div class="gstat">
            <div class="n">{{ $c['approx'] ? '≈ ' : '' }}{{ number_format($c['value']) }}</div>
            <div class="l">{{ $label }}</div>
          </div>
        @endforeach
      </div>
    @endif
  </section>

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
        <p style="margin:10px 0 0;font-size:.8rem;color:var(--ink-faint);">DEA &amp; MMIS are multi-valued (from <code>employee_additional_info</code>) and merge via dedup. <b>The <code>ssn_hash</code> tier is guarded:</b> CAMI source data carries filler SSNs (all-zero, sequential, repeated digits), and every person sharing one hashes identically, so an unguarded tier would collapse them into a single identity. Known placeholders are excluded by value, and any hash carried by more than <b>3</b> distinct people upstream is treated as filler and skipped &mdash; which catches the ones not on the list. Measured on the current hub: 17 filler hashes across 9,164 people, the worst carried by 9,072 of them.</p>
      </div>
      <div class="spec">
        <h4>Pass B — probabilistic (Jaro-Winkler)</h4>
        <div class="kvrow"><span>Name (over all aliases)</span><span class="v">0.45</span></div>
        <div class="kvrow"><span>DOB</span><span class="v">0.20</span></div>
        <div class="kvrow"><span>Address (any-vs-any)</span><span class="v">0.15</span></div>
        <div class="kvrow"><span>Provider type</span><span class="v">0.08</span></div>
        <div class="kvrow"><span>Shared exclusion</span><span class="v">0.07</span></div>
        <div class="kvrow"><span>ZIP</span><span class="v">0.05</span></div>
        <p style="margin:10px 0 0;font-size:.8rem;color:var(--ink-faint);">Auto-merge &ge; <b>0.92</b>; review band <b>0.75&ndash;0.92</b>; below &rarr; new. Hard-no: conflicting DOB, or two different valid NPIs. Blocks capped at 2000 (oversized flagged, never truncated).</p>
        <div class="notice" style="margin-top:10px;font-size:.8rem;">
          <b>Configured &ne; in effect.</b> <code>provider_type</code> (0.08) is declared but <b>not implemented</b> &mdash; <code>stg_person</code> carries no provider/entity-type column, so that weight never fires. The five weights that do fire sum to exactly <b>0.92</b>, which <em>is</em> <code>auto_merge_at</code> &mdash; so an automatic merge needs a flawless score on every remaining signal at once (name Jaro-Winkler 1.0 <em>and</em> exact DOB <em>and</em> address <em>and</em> ZIP <em>and</em> a shared exclusion). In practice Pass&nbsp;B lands in the review band or below, and the queue on <a href="{{ route('review') }}">Review</a> is where those decisions actually get made. The resolver logs a warning once per process while this holds. Rebalancing the weights (or lowering the threshold) changes merge behaviour across the whole hub, so it is left to Phase&nbsp;3 calibration against labeled data rather than patched here.</div>
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
      <tr><td><code>gp_identity_alias</code></td><td>Searchable index of alias names (<code>alias_name</code> + <code>alias_part</code>) &mdash; what <code>identity-search</code> matches aliases against. The <code>aliases</code> JSON on the profile cannot be indexed for this, so the index is a table.</td></tr>
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
    <p class="lede">Seven Artisan commands in two groups. The <code>gp:*</code> commands live in the
    <b>gp-cami</b> app and are the only things that write to the <code>golden_profile</code> hub. The
    <code>gpdash:*</code> commands live in <b>this</b> app, read the hub and write their results to the
    dashboard&rsquo;s own database &mdash; each is a full scan of a multi-million-row table, which is a batch
    job&rsquo;s work rather than a page load&rsquo;s. One of them can write to the hub, and says so.</p>

    <p class="eyebrow" style="margin-top:24px;">gp-cami &middot; building and maintaining the hub</p>

    <div class="cmd">
      <h4>php artisan gp:backfill</h4>
      <p><b>Mode&nbsp;1 &mdash; build the hub from the whole backlog.</b> Runs the entire pipeline as one
      set-based command (stage&nbsp;&rarr; index&nbsp;&rarr; resolve&nbsp;&rarr; enrich&nbsp;&rarr;
      dedup&nbsp;&rarr; rollup&nbsp;&rarr; finalize), turning every source record into a finished
      <code>gp_identity_profile</code> row. Staging and finalize run in parallel, and progress is
      checkpointed per stripe in <code>gp_watermark</code>, so an interrupted run resumes where it stopped
      instead of starting over. This is the one-time establishing run; <code>gp:sync</code> keeps the hub
      current afterwards.</p>
      <div class="opts">
        <div class="optrow"><code>system</code><span>Positional argument, not a flag &mdash; which source system to stage from, resolved against <code>gp_source_system</code>. Defaults to <code>streamline_local</code>.</span></div>
        <div class="optrow"><code>--from-id=</code> <code>--to-id=</code><span>Bound the source id range, inclusive. Use for a bounded sanity run, or to split one backfill across machines by id range.</span></div>
        <div class="optrow"><code>--chunk=2000</code><span>Staging read/insert batch size.</span></div>
        <div class="optrow"><code>--workers=16</code><span>Parallel degree for the stage and finalize phases.</span></div>
        <div class="optrow"><code>--restart</code><span>Clear the staging checkpoints and stage from the beginning. Without it, a re-run resumes.</span></div>
        <div class="optrow"><code>--legacy-finalize</code><span>Finalize per identity in shards instead of the set-based way. Kept as an escape hatch for comparing the two.</span></div>
        <div class="optrow internal"><code>--stage-only</code> <code>--segment=</code><span>Internal. The orchestrator spawns its own staging sub-workers with these; not meant to be run by hand.</span></div>
        <div class="optrow internal"><code>--finalize-shard=</code> <code>--shards=</code><span>Internal. The same, for the finalize phase.</span></div>
      </div>
    </div>

    <div class="cmd">
      <h4>php artisan gp:sync</h4>
      <p><b>Mode&nbsp;2 &mdash; incremental catch-up.</b> Processes only the source rows that changed since
      the <code>gp_watermark</code> high-water mark, running the same per-record resolve&nbsp;&rarr;&nbsp;finalize
      logic the backfill uses, then advances the mark. Idempotent: a re-run with nothing new to do does
      nothing. This is the one that belongs on a schedule.</p>
      <div class="opts">
        <div class="optrow"><code>system</code><span>Positional argument, as above. Defaults to <code>streamline_local</code>.</span></div>
        <div class="optrow"><code>--chunk=1000</code><span>How many changed rows to process per batch.</span></div>
      </div>
    </div>

    <div class="cmd">
      <h4>php artisan gp:rebuild-profile</h4>
      <p>Re-materializes the denormalized <code>gp_identity_profile</code> read model from the normalized
      tables &mdash; survivorship is re-applied and the wide row is rewritten. Nothing is re-resolved and no
      identity changes, so this is the safe command to reach for after changing a survivorship rule, or when
      one profile looks stale against its own evidence.</p>
      <div class="opts">
        <div class="optrow"><code>--identity=</code><span>Rebuild one identity. Omit to rebuild every profile.</span></div>
      </div>
    </div>

    <div class="cmd">
      <h4>php artisan gp:rebuild-aliases</h4>
      <p>Rebuilds <code>gp_identity_alias</code>, the searchable index of alias names that
      <code>/api/v1/identity-search</code> matches against alongside the canonical name. The materializers
      maintain it in step with the <code>aliases</code> JSON as they write it, so this command is for the
      initial build and for repairing drift &mdash; it re-resolves nothing and touches no table but this one.
      A full rebuild walks the staged alias rows (~108k) rather than the whole identity range.</p>
      <div class="opts">
        <div class="optrow"><code>--identity=</code><span>Rebuild one identity only.</span></div>
        <div class="optrow"><code>--from=</code> <code>--to=</code><span>Rebuild an <code>identity_id</code> range. Given either, the walk goes over identities instead of over staging.</span></div>
        <div class="optrow"><code>--verify</code><span>Report coverage instead of rebuilding: how many identities the index holds, against how many carry a surname alias in their JSON rollup. A difference means a materialize ran without the indexer &mdash; the drift this table is most exposed to. Exits non-zero when the two disagree, so it works as a cron check.</span></div>
      </div>
    </div>

<pre class="code"># the establishing run
php artisan gp:backfill

# bounded slice, for a sanity check on a smaller box
php artisan gp:backfill --from-id=1 --to-id=50000 --workers=8

# incremental from then on
php artisan gp:sync

# is the alias index still in step with the profiles?
php artisan gp:rebuild-aliases --verify</pre>

    <p class="eyebrow" style="margin-top:26px;">gp-cami-dashboard &middot; precomputing what the pages show</p>

    <div class="cmd">
      <h4>php artisan gpdash:snapshot</h4>
      <p>Takes one dated reading of the hub&rsquo;s aggregates and stores it in this app&rsquo;s database. It
      is what gives the stat cards their sparkline and their &ldquo;since last snapshot&rdquo; delta, and what
      fills <a href="{{ route('review') }}">Review</a>, <a href="{{ route('quality') }}">Quality</a>,
      <a href="{{ route('accounts') }}">Accounts</a> and <a href="{{ route('pipeline') }}">Pipeline</a>.
      Nightly is the intended cadence: the history only exists because something recorded it, and a flat
      delta on a table that should be growing is the whole point of keeping it.</p>
      <div class="opts">
        <div class="optrow"><code>--only=</code><span>Run a subset, comma-separated. An unrecognised name is refused, with the valid list printed. The five sections, in run order, are below.</span></div>
        <div class="optrow"><code>&nbsp;&nbsp;counts</code><span>Exact row counts for the stats-board tables &mdash; today&rsquo;s data point per table.</span></div>
        <div class="optrow"><code>&nbsp;&nbsp;buckets</code><span>How many source records each identity carries. A healthy hub is dominated by the <code>1</code> bucket; mass in the high buckets is over-merge.</span></div>
        <div class="optrow"><code>&nbsp;&nbsp;links</code><span>Link states and match-score bands &mdash; the raw material of the review queue.</span></div>
        <div class="optrow"><code>&nbsp;&nbsp;accounts</code><span>The per-account rollup. <code>gp_source_link.account_id</code> is unindexed on the hub, so this is the one place that scan is paid for.</span></div>
        <div class="optrow"><code>&nbsp;&nbsp;quality</code><span>Over-merge candidates: the identities carrying the most source records, each re-checked for members that disagree on a high-precision key. A shared key is why they merged; a conflicting key is why they should not have.</span></div>
        <div class="optrow"><code>--over-merge=25</code><span>The <code>record_count</code> above which an identity is flagged as an over-merge candidate.</span></div>
        <div class="optrow"><code>--top=50</code><span>How many of those candidates to keep.</span></div>
      </div>
    </div>

    <div class="cmd">
      <h4>php artisan gpdash:merge-basis</h4>
      <p>Works out, for each multi-record identity, <em>why</em> its members are held to be one person &mdash;
      and where they nonetheless disagree. The hub cannot answer this itself:
      <code>gp_source_link.match_key</code> is stamped when the link is first made and never rewritten by a
      later dedup merge, so the stored key explains the original binding rather than the identity as it now
      stands. This derives the basis from the evidence and stores it.</p>
      <div class="opts">
        <div class="optrow"><code>--identity=</code><span>Compute for one identity id only.</span></div>
        <div class="optrow"><code>--min-records=2</code><span>Only consider identities carrying at least this many source records &mdash; a single-record identity has no merge to explain.</span></div>
        <div class="optrow"><code>--limit=1000</code><span>How many identities to process, highest <code>record_count</code> first, so a capped run spends itself on the ones most likely to be wrong.</span></div>
        <div class="optrow"><code>--refresh</code><span>Recompute identities that already have a stored basis. Without it those are skipped and the run only fills gaps.</span></div>
      </div>
    </div>

    <div class="cmd">
      <h4>php artisan gpdash:index-advisor</h4>
      <p>Reports the hub indexes the newer dashboard pages want, each with the reason it matters and the DDL
      to create it &mdash; the account lens and the review queue both scan or filesort without them.
      Read-only by default. <b>This is the one dashboard command that can write to gp-cami:</b>
      <code>--apply</code> creates the missing indexes, after a confirmation.</p>
      <div class="opts">
        <div class="optrow"><code>--apply</code><span>Create the missing indexes on the hub rather than only reporting them.</span></div>
      </div>
    </div>

<pre class="code"># nightly
php artisan gpdash:snapshot

# just the sections the review queue needs
php artisan gpdash:snapshot --only=links,quality

# precompute the merge basis for the 5000 largest identities
php artisan gpdash:merge-basis --limit=5000

# what is the hub missing, and what would it cost to add?
php artisan gpdash:index-advisor</pre>
  </section>

  {{-- ---------------- API ---------------- --}}
  <section class="doc" id="api">
    <p class="eyebrow">Integration</p>
    <h2>API (v1)</h2>
    <p class="lede">JSON over HTTP, prefix <code>/api/v1</code>. Both endpoints sit behind a
    Sanctum bearer token (<code>auth:sanctum</code>) and are rate limited to <b>120 requests a
    minute</b> per caller. Responses expose <code>ssn_last_four</code> only &mdash; never the SSN.</p>

    <div class="spec" style="margin-bottom:16px;">
      <h4><span class="method">POST</span><code>/api/v1/identity-search</code></h4>
      <p style="font-size:.85rem;color:var(--ink-soft);margin:0 0 8px;">Find golden identities by name (last name required). Matches the canonical name <em>and</em> aliases, the latter through <code>gp_identity_alias</code>.</p>
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
      <p style="font-size:.8rem;color:var(--ink-faint);margin:8px 0 0;">A credential also has to be unexpired to qualify: <code>expiry_date IS NULL OR expiry_date &gt;= CURDATE()</code>. Qualifying status codes: 20,30,40,45,65,70,80,85,90 · excluded: 0,10,50,60,100.</p>
    </div>
  </section>

    <div class="notice" style="margin-top:16px;">
      <b>Oversized rollups are omitted, not truncated.</b> The JSON columns on
      <code>gp_identity_profile</code> (<code>identifiers</code>, <code>addresses</code>,
      <code>licenses</code>, <code>credentials</code>, <code>exclusions</code>,
      <code>accounts</code>, <code>aliases</code>, <code>source_records</code>,
      <code>resolutions</code>) are unbounded &mdash; one identity carries a 69MB credentials blob,
      enough to exhaust PHP&rsquo;s memory limit hydrating a single result. Any column over
      <b>2MB</b> is dropped from the response and named in <code>meta.omitted_fields</code>, so a
      caller can tell a genuinely empty list apart from one that was withheld. <b>Check that key
      before treating an absent rollup as &ldquo;none&rdquo;.</b>
    </div>

  {{-- ---------------- PLAYGROUND ---------------- --}}
  <section class="doc" id="playground">
    <p class="eyebrow">Integration</p>
    <h2>Try it</h2>
    <p class="lede">The two endpoints above, live. The call is proxied through this app rather than made
    from the browser — the gp-cami app is a different origin with no CORS allowance for this one, and a
    bearer token typed into a form should not travel in a request the page can be tricked into replaying.
    Target: <code>{{ config('gpcami.api_base') }}</code> (set <code>GPCAMI_API_BASE</code> to change it).</p>

    <div class="play-bar">
      <select id="pg-endpoint">
        <option value="identity-search">POST /api/v1/identity-search</option>
        <option value="credential-search">POST /api/v1/credential-search</option>
      </select>
      <input type="password" id="pg-token" placeholder="Sanctum bearer token" autocomplete="off" style="min-width:280px;">
      <button class="btn" id="pg-send" type="button">
        <span class="spinner" aria-hidden="true"></span><span class="btn-label">Send</span>
      </button>
      <span class="play-status" id="pg-status"></span>
    </div>

    <div class="play">
      <div>
        <p class="eyebrow">Request body</p>
        <textarea id="pg-body" spellcheck="false">{
  "last_name": "Smith",
  "first_name": "John",
  "per_page": 5
}</textarea>
      </div>
      <div>
        <p class="eyebrow">Response</p>
        <pre class="json" id="pg-out" style="min-height:150px;margin:0;">Nothing sent yet.</pre>
      </div>
    </div>

    <script>
      (function () {
        var btn = document.getElementById('pg-send');
        var out = document.getElementById('pg-out');
        var status = document.getElementById('pg-status');
        var bodies = {
          'identity-search': '{\n  "last_name": "Smith",\n  "first_name": "John",\n  "per_page": 5\n}',
          'credential-search': '{\n  "last_name": "Smith",\n  "first_name": "John",\n  "registry": "CA-RN"\n}'
        };

        // Swapping endpoint swaps in that endpoint's example, but never
        // overwrites a body the user has actually edited.
        var pristine = true;
        document.getElementById('pg-body').addEventListener('input', function () { pristine = false; });
        document.getElementById('pg-endpoint').addEventListener('change', function (e) {
          if (pristine) document.getElementById('pg-body').value = bodies[e.target.value];
        });

        btn.addEventListener('click', function () {
          btn.classList.add('loading');
          btn.disabled = true;
          status.textContent = '';
          status.className = 'play-status';
          out.textContent = 'Sending…';

          fetch("{{ route('api.try') }}", {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
            },
            body: JSON.stringify({
              endpoint: document.getElementById('pg-endpoint').value,
              token: document.getElementById('pg-token').value,
              body: document.getElementById('pg-body').value
            })
          })
            .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, body: j }; }); })
            .then(function (r) {
              if (!r.ok) {
                status.textContent = 'failed';
                status.className = 'play-status bad';
                out.textContent = r.body.error || JSON.stringify(r.body, null, 2);
                return;
              }
              var upstream = r.body.status;
              status.textContent = upstream + ' · ' + r.body.ms + 'ms';
              status.className = 'play-status ' + (upstream >= 200 && upstream < 300 ? 'ok' : 'bad');
              out.textContent = typeof r.body.body === 'string'
                ? r.body.body
                : JSON.stringify(r.body.body, null, 2);
            })
            .catch(function (e) {
              status.textContent = 'error';
              status.className = 'play-status bad';
              out.textContent = e.message;
            })
            .finally(function () {
              btn.classList.remove('loading');
              btn.disabled = false;
            });
        });
      })();
    </script>
  </section>

  {{-- ---------------- SSN ---------------- --}}
  <section class="doc" id="ssn" style="border-bottom:none;">
    <p class="eyebrow">Security</p>
    <h2>SSN handling</h2>
    <p class="lede">SSNs are matched on a hash, never in plaintext: <code>sha512(ssn + plaintext_key)</code>,
    parity with <code>streamline_local.social_security_num</code>. The hash is the join key; the stored
    value is encrypted; API responses return <code>ssn_last_four</code> only. Identity resolution uses the
    hash so two records with the same SSN merge without either side handling the raw number.</p>
    <p class="lede" style="margin-top:12px;">Two caveats worth knowing before trusting SSN matching:</p>
    <div class="spec">
      <div class="kvrow"><span>Filler SSNs are excluded</span><span class="v">placeholder list + &gt;3 people per hash</span></div>
      <div class="kvrow"><span>Prod needs <code>GP_SSN_PLAINTEXT_KEY</code></span><span class="v">else SSN matching is unavailable</span></div>
      <p style="margin:10px 0 0;font-size:.8rem;color:var(--ink-faint);">The key must be the same one
      CAMI uses or the hashes will not align. Local dev leaves it unset and resolves through the
      <code>streamline_local</code> encryption-key registry; in prod the key lives behind KMS and is
      not derivable from the source DB, so it has to be set explicitly &mdash; and the app says so
      rather than silently hashing to null. Withholding the full SSN from API responses is enforced
      structurally &mdash; the resource never emits <code>ssn_hash</code> or the ciphertext &mdash;
      not by a config flag.</p>
    </div>
  </section>
@endsection
