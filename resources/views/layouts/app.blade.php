<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The docs-page playground posts back to this app before proxying on to
         the gp-cami API, so it needs the session's CSRF token. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'gp-cami dashboard')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="icon" href="{{ asset('sv-logo.png') }}">
    @stack('head')
</head>
<body>
    <div id="header">
        <div class="bar">
            <img src="{{ asset('sv-logo.png') }}" alt="Streamline Verify">
            <span class="title">gp-cami dashboard <span class="muted" style="font-weight:400;font-size:12px;">· dev</span></span>
        </div>
        <nav class="tabs">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('review') }}" class="{{ request()->routeIs('review*') ? 'active' : '' }}">Review</a>
            <a href="{{ route('quality') }}" class="{{ request()->routeIs('quality') ? 'active' : '' }}">Quality</a>
            <a href="{{ route('accounts') }}" class="{{ request()->routeIs('accounts*') ? 'active' : '' }}">Accounts</a>
            <a href="{{ route('pipeline') }}" class="{{ request()->routeIs('pipeline') ? 'active' : '' }}">Pipeline</a>
            <a href="{{ route('features') }}" class="{{ request()->routeIs('features') ? 'active' : '' }}">Overview</a>
        </nav>
    </div>

    <main>
        @yield('content')
    </main>

    <footer>
        gp-cami dashboard — read-only view of the <b>golden_profile</b> hub. Development use only.
    </footer>

    <script>
        // "Match data" buttons live inside markup that is sometimes injected by
        // fetch(), so the handler is delegated from the document instead of bound
        // per button. Each row's payload is fetched once and then toggled.
        (function () {
            var base = "{{ url('/match') }}";
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.js-match');
                if (!btn) return;

                var row = btn.closest('tr');
                var next = row.nextElementSibling;
                if (next && next.classList.contains('match-json-row')) {
                    var open = next.style.display !== 'none';
                    next.style.display = open ? 'none' : '';
                    btn.textContent = open ? 'Match data' : 'Hide';
                    return;
                }

                var tr = document.createElement('tr');
                tr.className = 'match-json-row';
                tr.innerHTML = '<td colspan="' + (btn.dataset.cols || 6) + '">'
                    + '<div class="muted"><span class="spinner dark"></span> Loading match data…</div></td>';
                row.parentNode.insertBefore(tr, row.nextSibling);
                btn.textContent = 'Hide';

                fetch(base + '/' + btn.dataset.kind + '/' + btn.dataset.id,
                      { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, body: j }; }); })
                    .then(function (r) {
                        var pre = document.createElement('pre');
                        pre.className = 'json';
                        pre.textContent = JSON.stringify(r.body, null, 2);
                        var cell = tr.firstChild;
                        cell.innerHTML = '';
                        if (!r.ok) {
                            var msg = document.createElement('div');
                            msg.className = 'error';
                            msg.textContent = r.body.error || 'Could not load the match data.';
                            cell.appendChild(msg);
                        } else {
                            cell.appendChild(pre);
                        }
                    })
                    .catch(function (err) {
                        tr.firstChild.innerHTML = '<div class="error">Could not load the match data: '
                            + err.message + '</div>';
                    });
            });
        })();
    </script>
</body>
</html>
