<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ config('app.name', 'gp-cami dashboard') }} · Overview</title>
<style>
  :root{
    --navy:#00284c; --navy-deep:#001a33; --orange:#f47d27; --gold:#c9a227; --gold-light:#f0d98a;
    --gold-grad:linear-gradient(135deg,#a67c00,#e9c766 45%,#c9a227 70%,#f3e3a6);
    --ink:#212121; --ink-soft:#4f4f4f; --ink-faint:#7d858e; --surface:#fff; --surface-2:#f6f7f9;
    --line:#e1e1e1; --bg:#f2f2f2; --ok:#2f7d54; --ok-wash:#e5f1ea; --crit:#b34534;
    --sans:"Open Sans","Segoe UI",Helvetica,Arial,sans-serif; --mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
  }
  @media (prefers-color-scheme:dark){:root{
    --ink:#e7ebf0; --ink-soft:#aeb7c2; --ink-faint:#7c8794; --surface:#131b26; --surface-2:#0f1620;
    --line:#23303f; --bg:#0b1017; --navy:#0d2c4a; --ok:#5fbd85; --ok-wash:#16251c;
  }}
  *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.6}
  a{color:var(--orange);text-decoration:none} code{font-family:var(--mono);font-size:.85em;background:var(--surface-2);border:1px solid var(--line);border-radius:3px;padding:1px 5px}
  .wrap{max-width:980px;margin:0 auto;padding:0 24px}
  .topbar{background:linear-gradient(90deg,var(--navy),var(--navy-deep));border-bottom:4px solid var(--orange)}
  .topbar .wrap{display:flex;align-items:center;justify-content:space-between;padding:16px 24px}
  .brand{color:#fff;font-weight:800;font-size:1.15rem} .brand b{background:var(--gold-grad);-webkit-background-clip:text;background-clip:text;color:transparent}
  .topnav a{color:#cdd8e4;font-size:.9rem;margin-left:18px} .topnav a.btn{background:var(--orange);color:#fff;border-radius:6px;padding:7px 14px;font-weight:700}
  .hero{background:linear-gradient(180deg,var(--navy),var(--navy-deep));color:#fff;padding:44px 0 40px;position:relative;overflow:hidden}
  .hero::after{content:"";position:absolute;left:0;right:0;bottom:0;height:4px;background:var(--gold-grad)}
  .hero h1{font-size:2.2rem;font-weight:800;letter-spacing:-.02em;margin:0} .hero h1 .g{background:var(--gold-grad);-webkit-background-clip:text;background-clip:text;color:transparent}
  .hero p{color:#cdd8e4;max-width:64ch;margin:12px 0 0;font-size:1.05rem}
  section{padding:34px 0;border-bottom:1px solid var(--line)}
  h2{font-size:1.3rem;font-weight:800;color:var(--navy);margin:0 0 6px} @media (prefers-color-scheme:dark){h2{color:#cdd8e4}}
  .eyebrow{font-family:var(--mono);font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--orange);font-weight:700;margin:0 0 6px}
  p.sub{color:var(--ink-soft);max-width:70ch;margin:0 0 18px}
  .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
  .card{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:18px;position:relative}
  .card::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:var(--gold-grad);border-radius:10px 10px 0 0}
  .card h3{margin:6px 0 6px;font-size:1rem;color:var(--navy)} @media (prefers-color-scheme:dark){.card h3{color:#e7ebf0}}
  .card p{margin:0;font-size:.88rem;color:var(--ink-soft)}
  .badge{display:inline-block;font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;margin-left:6px;color:var(--ok);background:var(--ok-wash)}
  .cta{display:inline-block;background:var(--orange);color:#fff;font-weight:700;border-radius:6px;padding:10px 18px;margin-top:8px}
  footer{padding:24px 0 44px;color:var(--ink-faint);font-family:var(--mono);font-size:12px}
  @media (max-width:820px){.cards{grid-template-columns:1fr 1fr}} @media (max-width:560px){.cards{grid-template-columns:1fr}}
</style>
</head>
<body>
  <div class="topbar"><div class="wrap">
    <div class="brand">gp&#8209;cami <b>dashboard</b></div>
    <nav class="topnav">
      @auth <a href="{{ url('/dashboard') }}" class="btn">Open dashboard</a>
      @else @if(Route::has('login'))<a href="{{ route('login') }}" class="btn">Log in</a>@endif @endauth
    </nav>
  </div></div>

  <header class="hero"><div class="wrap">
    <h1>Golden <span class="g">Profile</span> dashboard</h1>
    <p>A read-only window into the identities resolved by gp-cami — search a person, open one
    consolidated golden record, and watch the hub fill in real time. It never writes to the hub.</p>
    @auth <a href="{{ url('/dashboard') }}" class="cta">Open dashboard →</a>
    @else @if(Route::has('login'))<a href="{{ route('login') }}" class="cta">Log in →</a>@endif @endauth
  </div></header>

  <div class="wrap">
    <section>
      <p class="eyebrow">What it does</p>
      <h2>Features</h2>
      <p class="sub">Everything is served from the materialized <code>gp_identity_profile</code> on the shared
      <code>golden_profile</code> hub — one indexed read per person, no joins.</p>
      <div class="cards">
        <div class="card"><h3>Identity search</h3><p>Find a person by name across canonical fields and aliases; open any match.</p></div>
        <div class="card"><h3>Consolidated profile</h3><p>One golden record: names, DOB, keys, addresses, licenses, credentials, exclusions and provenance — collapsed from every source row.</p></div>
        <div class="card"><h3>Multi-valued identifiers <span class="badge">new</span></h3><p>DEA and MMIS numbers (from <code>employee_additional_info</code>) surface on the profile and drive matching — people sharing a DEA merge into one identity.</p></div>
        <div class="card"><h3>Exclusion flag</h3><p>An active-exclusion badge on every profile for at-a-glance compliance status.</p></div>
        <div class="card"><h3>Stats board</h3><p>Live counts across the hub tables — identities, source links, licenses, identifiers, credential and exclusion links.</p></div>
        <div class="card"><h3>Read-only by design</h3><p>The dashboard only reads gp-cami's hub; it never mutates golden data.</p></div>
      </div>
    </section>

    <footer>gp-cami dashboard · reads the golden_profile hub · Laravel v{{ app()->version() }}</footer>
  </div>
</body>
</html>
