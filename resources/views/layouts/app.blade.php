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
                    // Collapsed state is kept on the row, not read back off
                    // style.display: a paged table hides rows that are simply on
                    // another page, and inferring "closed" from that reopened
                    // every payload the moment its page came back into view.
                    var collapsed = next.dataset.collapsed === '1';
                    next.dataset.collapsed = collapsed ? '0' : '1';
                    next.style.display = collapsed ? '' : 'none';
                    btn.textContent = collapsed ? 'Hide' : 'Match data';
                    return;
                }

                var tr = document.createElement('tr');
                tr.className = 'match-json-row';
                tr.dataset.collapsed = '0';
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

    <script>
        // Client-side paging for the profile view's rollup tables.
        //
        // Those tables are not database queries — each one is a single JSON
        // column on gp_identity_profile that has already been read, decoded and
        // rendered, so "page 2" is a display concern and a round trip for it
        // would re-read the whole rollup to show twenty-five more rows. Server
        // paging is used everywhere the rows are actually rows (search, review
        // queue, accounts, quality); this is for the lists that arrive whole.
        (function () {
            var DEFAULT_SIZE = 25;

            // Header rows are markup, and a payload row injected by "Match data"
            // belongs to the row above it rather than being a row of its own.
            function dataRows(table) {
                return Array.prototype.filter.call(table.rows, function (row) {
                    return row.cells.length
                        && !row.classList.contains('match-json-row')
                        && !row.querySelector('th');
                });
            }

            function attached(row) {
                var next = row.nextElementSibling;
                return (next && next.classList.contains('match-json-row')) ? next : null;
            }

            function paint(table, state) {
                var rows = dataRows(table);
                var pages = Math.max(1, Math.ceil(rows.length / state.size));
                state.page = Math.min(Math.max(1, state.page), pages);

                var from = (state.page - 1) * state.size;
                var to = Math.min(from + state.size, rows.length);

                rows.forEach(function (row, i) {
                    var visible = i >= from && i < to;
                    row.style.display = visible ? '' : 'none';
                    var payload = attached(row);
                    if (payload) {
                        payload.style.display = (visible && payload.dataset.collapsed !== '1') ? '' : 'none';
                    }
                });

                state.info.textContent = (from + 1).toLocaleString() + '–' + to.toLocaleString()
                    + ' of ' + rows.length.toLocaleString();
                state.prev.disabled = state.page === 1;
                state.next.disabled = state.page === pages;
            }

            function button(label) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'btn-mini';
                b.textContent = label;
                return b;
            }

            window.gpPageTables = function (root) {
                (root || document).querySelectorAll('table[data-paged]').forEach(function (table) {
                    if (table.dataset.pagedInit === '1') return;

                    var size = parseInt(table.dataset.paged, 10) || DEFAULT_SIZE;
                    if (dataRows(table).length <= size) return;   // nothing to page
                    table.dataset.pagedInit = '1';

                    var bar = document.createElement('div');
                    bar.className = 'pager table-pager';
                    var state = {
                        page: 1,
                        size: size,
                        prev: button('← Previous'),
                        next: button('Next →'),
                        info: document.createElement('span'),
                    };
                    state.info.className = 'pager-info muted';
                    bar.appendChild(state.prev);
                    bar.appendChild(state.info);
                    bar.appendChild(state.next);
                    table.parentNode.insertBefore(bar, table.nextSibling);

                    state.prev.addEventListener('click', function () {
                        state.page--;
                        paint(table, state);
                    });
                    state.next.addEventListener('click', function () {
                        state.page++;
                        paint(table, state);
                    });

                    paint(table, state);
                });
            };

            window.gpPageTables(document);
        })();
    </script>
</body>
</html>
