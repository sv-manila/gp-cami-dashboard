<?php

namespace App\Http\Controllers;

use App\Models\GpProfile;
use App\Models\MergeBasis;
use App\Models\StatSnapshot;
use App\Services\ProfileSearch;
use App\Services\QueryInput;
use App\Services\SearchInputException;
use App\Services\SearchResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    /** Cap on rows pulled per basis query — blob identities have thousands of links. */
    private const MAX_BASIS_ROWS = 200;

    /** Rollup JSON columns bigger than this are not loaded (PHP memory_limit is 128M). */
    private const MAX_JSON_BYTES = 2097152;

    /** Above this many source records, skip the live shared-key probe (seconds, not ms). */
    private const MAX_DERIVE_LINKS = 500;

    /**
     * Rows rendered per rollup table on the profile view.
     *
     * The tables are paged in the browser (the rollup is one JSON column, so
     * there is no cheaper page to fetch), which means every row inside the cap
     * is reachable — the cap is now about how much HTML one response carries,
     * not about how much of the data a reader may see.
     */
    public const PROFILE_ROW_CAP = 500;

    public function __construct(
        private SearchResolver $resolver,
        private ProfileSearch $search,
    ) {}

    /** One page: Golden Profile Stats (with trend) + smart search. */
    public function index(Request $request)
    {
        $stats = $this->stats();
        $trends = StatSnapshot::series(StatSnapshot::TABLE_COUNT, 30);

        $empty = ['paginator' => null, 'matched_on' => null, 'error' => null, 'suggestions' => []];

        // queryFromRequest is inside the guard too. It reads query parameters, and
        // array input (?q[]=x) raises SearchInputException from QueryInput — before
        // this it was an uncaught ErrorException and a 500 with a debug page.
        try {
            $query = $this->queryFromRequest($request);
            $searched = $query !== '' && $request->hasAny(['q', 'first_name', 'last_name']);
            $criteria = $this->resolver->resolve($query);
            $opts = $this->optionsFromRequest($request, $criteria);
        } catch (SearchInputException $e) {
            $query ??= '';
            $criteria ??= $this->resolver->resolve('');
            // A rejected filter has to be reported, not dropped — see
            // normalizeDob(). Fall back to safe defaults so the page still renders
            // with the stats board and the message.
            $opts = ['per_page' => QueryInput::perPage($request, (int) config('gpcami.per_page', 50)),
                'cursor' => null,
                'prefix' => $request->boolean('prefix'), 'dob' => null,
                'exclusions_only' => $request->boolean('excl'), 'last_name' => null];

            $result = $empty;
            $result['error'] = $e->getMessage();
            $searched = false;
        }

        $result ??= $searched ? $this->search->run($criteria, $opts) : $empty;

        return view('dashboard', [
            'stats'       => $stats,
            'trends'      => $trends,
            'query'       => $query,
            'criteria'    => $criteria,
            'searched'    => $searched,
            'results'     => $result['paginator'],
            'matchedOn'   => $result['matched_on'],
            'error'       => $result['error'],
            'suggestions' => $result['suggestions'],
            'prefix'      => (bool) $opts['prefix'],
            'dob'         => $opts['dob'],
            'exclOnly'    => (bool) $opts['exclusions_only'],
            // Coerced, not passed raw: ?identity[]=x reached the view as an array.
            'selected'    => is_array($request->query('identity')) ? null : $request->query('identity'),
            'perPage'     => (int) $opts['per_page'],
            'pageSizes'   => QueryInput::PAGE_SIZES,
        ]);
    }

    /**
     * The current search as CSV or JSON.
     *
     * Deliberately the same code path as the on-screen results — an export that
     * silently applies different filters than the page above it is worse than
     * no export at all.
     */
    public function export(Request $request, string $format = 'csv')
    {
        if (! in_array($format, ['csv', 'json'], true)) {
            abort(404);
        }

        $criteria = $this->resolver->resolve($this->queryFromRequest($request));

        try {
            // optionsFromRequest validates the dob filter and can reject it, so it
            // belongs inside the same guard as stream(): an export must never
            // silently drop a filter the on-screen results would have applied.
            $opts = $this->optionsFromRequest($request, $criteria);
            $rows = $this->search->stream($criteria, $opts);
        } catch (SearchInputException $e) {
            return response($e->getMessage(), 422);
        }

        $columns = config('gpcami.list_columns');
        $name = 'gp-cami-' . preg_replace('/[^a-z0-9]+/i', '-', $criteria['raw'] ?: 'search')
            . '-' . now()->format('Ymd-His') . '.' . $format;

        return new StreamedResponse(function () use ($rows, $columns, $format) {
            // A streamed export is not a page render and must not inherit the web
            // request's max_execution_time (30s here). Exceeding it is a FATAL, not
            // a catchable Throwable, so the try/catch below cannot help: PHP tore
            // the script down mid-stream and the error handler appended its debug
            // page into the .csv. Raise the wall clock for the download, but keep
            // it finite so a runaway still dies; the statement itself is separately
            // bounded by gpcami.export_timeout_ms.
            $budget = (int) config('gpcami.export_time_limit', 300);
            set_time_limit($budget);

            // Stop ourselves before PHP does. set_time_limit alone was not enough:
            // the per-statement budget (export_timeout_ms) applies to EACH lazy page,
            // so up to 20 pages of 120s is a 2400s ceiling against a 300s wall clock.
            // Measured exports already reached 167.8s and 234.6s, so crossing 300s
            // and dying on the uncatchable fatal was a matter of one slower run.
            // Deadline leaves a margin to write the marker and close the file.
            $deadline = microtime(true) + max(5, $budget - 15);
            $timedOut = false;

            $handle = fopen('php://output', 'w');

            // The status line and Content-Type are already on the wire by the time
            // this callback runs, so a query failure in here cannot become a 4xx.
            // Without a catch, the exception handler appended its debug page to
            // the download: a `.csv` that opened as 805KB of HTML behind a 200 and
            // zero data rows. Fail in-band, in the file's own format, and log it.
            $written = 0;
            $error = null;

            // JSON is an OBJECT, not a bare array. The previous failure path wrote
            // `[{…}], "error": "…"` after an open array, which is not valid JSON at
            // all ("Extra data: line 4 column 2") — so a failed JSON export lost
            // every row it had already streamed. Wrapping the rows in a envelope
            // means the same file can carry both the data and the status.
            try {
                if ($format === 'csv') {
                    fputcsv($handle, $columns);
                    foreach ($rows as $row) {
                        if (microtime(true) > $deadline) {
                            $timedOut = true;
                            break;
                        }
                        fputcsv($handle, array_map(fn ($c) => self::csvSafe($row->{$c}), $columns));
                        $written++;
                    }
                } else {
                    // Streamed so a 10k-row export is never held in memory in full.
                    fwrite($handle, "{\n  \"data\": [\n");
                    foreach ($rows as $row) {
                        if (microtime(true) > $deadline) {
                            $timedOut = true;
                            break;
                        }
                        fwrite($handle, ($written ? ",\n" : '')
                            . '    ' . json_encode($row->only($columns), JSON_UNESCAPED_SLASHES));
                        $written++;
                    }
                    fwrite($handle, "\n  ],\n");
                }
            } catch (\Throwable $e) {
                $error = 'The hub query failed part-way through this export.';
                Log::error('gp-cami export failed mid-stream', [
                    'format' => $format, 'rows_written' => $written, 'exception' => $e->getMessage(),
                ]);
            }

            if ($timedOut) {
                $error = 'This export hit its time limit before all rows were written.';
                Log::warning('gp-cami export truncated at the time budget', [
                    'format' => $format, 'rows_written' => $written, 'budget_seconds' => $budget,
                ]);
            }

            // Status is stated in-band either way: the response line and headers
            // went out before this callback ran, so a 200 cannot be retracted and a
            // trailer header cannot be added. A consumer checks "complete".
            if ($format === 'csv') {
                if ($error) {
                    fputcsv($handle, ['# EXPORT INCOMPLETE', $written, $error]);
                } else {
                    fputcsv($handle, ['# EXPORT COMPLETE', $written]);
                }
            } else {
                fwrite($handle, '  "complete": '.($error ? 'false' : 'true').",\n");
                fwrite($handle, '  "row_count": '.$written.",\n");
                fwrite($handle, '  "error": '.json_encode($error)."\n}\n");
            }

            fclose($handle);
        }, 200, [
            'Content-Type'        => $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Export-Limit'      => (string) config('gpcami.export_limit'),
        ]);
    }

    /**
     * A date-of-birth filter is only meaningful as a real Y-m-d date.
     *
     * Anything else was passed through to the query as-is, where MySQL compared a
     * DATE column against a non-date, matched nothing, and produced a normal
     * "0 results" page. `?dob=notadate` and `?dob=' OR 1=1` were therefore
     * indistinguishable from "this person is not in the hub" — the most misleading
     * possible answer, because the user's actual filter was silently discarded.
     *
     * @throws SearchInputException
     */
    private static function normalizeDob($value): ?string
    {
        $dob = trim((string) $value);
        if ($dob === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);

        // createFromFormat accepts out-of-range parts (2026-13-45) and rolls them
        // over, so round-trip the result to reject anything that was not already a
        // valid calendar date.
        if (! $parsed || $parsed->format('Y-m-d') !== $dob) {
            throw new SearchInputException(
                'Date of birth must be a calendar date in YYYY-MM-DD form (for example 1985-04-23). '
                .'The filter was ignored rather than applied — clear or correct it.',
            );
        }

        return $dob;
    }

    /**
     * Neutralise spreadsheet formula injection.
     *
     * Excel and Sheets evaluate a cell whose text begins with =, +, -, @, or a
     * leading tab/CR as a formula, which turns an exported name into code that
     * runs on the reviewer's machine. Names in this hub come from scraped
     * registries, so the content is not trustworthy. Prefixing with an
     * apostrophe keeps the value readable while forcing it to stay text.
     */
    private static function csvSafe($value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    /**
     * Search terms arrive two ways: the smart box (`q`), and the older
     * first_name/last_name pair that existing bookmarks and the profile page's
     * "other records under this name" links still use.
     */
    private function queryFromRequest(Request $request): string
    {
        if ($request->filled('q')) {
            return QueryInput::string($request, 'q');
        }

        $first = QueryInput::string($request, 'first_name');
        $last = QueryInput::string($request, 'last_name');

        return trim($first . ' ' . $last);
    }

    /** @return array<string,mixed> */
    private function optionsFromRequest(Request $request, array $criteria): array
    {
        // An ssn4: term needs a surname from somewhere; accept it either as a
        // separate field or from the older last_name parameter.
        $last = QueryInput::firstString($request, ['last', 'last_name']);

        return [
            // Whitelisted (QueryInput::PAGE_SIZES), not free-form: the page size
            // is how many gp_identity_profile rows one unauthenticated request
            // can pull off the hub at a time.
            'per_page'        => QueryInput::perPage($request, (int) config('gpcami.per_page', 50)),
            'cursor'          => QueryInput::string($request, 'cursor') ?: null,
            'prefix'          => $request->boolean('prefix'),
            'dob'             => self::normalizeDob(QueryInput::string($request, 'dob')),
            'exclusions_only' => $request->boolean('excl'),
            'last_name'       => $last ?: ($criteria['last'] ?? null),
        ];
    }

    /**
     * Stats board counts, with the previous snapshot for a day-over-day delta.
     *
     * @return array<string,array{count:?int,error:?string,approx:bool,delta:?int}>
     */
    private function stats(): array
    {
        $conn = config('gpcami.connection');
        $ttl = (int) config('gpcami.cache_ttl', 60);

        $stats = Cache::remember('gpcami.stats', $ttl, function () use ($conn) {
            $out = [];
            foreach (config('gpcami.stats_tables') as $label => $table) {
                try {
                    // Exact count, but capped so one slow table can't hang the page.
                    // On a healthy hub COUNT(*) over the 13M-row tables still takes
                    // 3-5s (InnoDB has no stored row count), so those legitimately
                    // fall through to the estimate — that is normal, not a busy hub.
                    $r = DB::connection($conn)->selectOne("SELECT /*+ MAX_EXECUTION_TIME(1500) */ COUNT(*) c FROM `$table`");
                    $out[$label] = ['count' => (int) $r->c, 'error' => null, 'approx' => false];
                } catch (\Throwable $e) {
                    // Fell over the cap (or errored) — use the instant approximate
                    // row estimate from table metadata instead of blocking.
                    try {
                        $r = DB::connection($conn)->selectOne(
                            'SELECT table_rows c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                            [$table],
                        );
                        $out[$label] = ['count' => (int) ($r->c ?? 0), 'error' => null, 'approx' => true];
                    } catch (\Throwable $e2) {
                        $out[$label] = ['count' => null, 'error' => 'unavailable', 'approx' => false];
                    }
                }
            }

            return $out;
        });

        // Yesterday's recorded value, so a stalled rollup shows up as a flat
        // delta rather than a number nobody can compare against.
        // Keep the snapshot's own approx flag: a stored estimate is no more
        // comparable than a live one.
        $previous = StatSnapshot::query()
            ->where('metric', StatSnapshot::TABLE_COUNT)
            ->where('captured_on', '<', now()->toDateString())
            ->orderByDesc('captured_on')
            ->get()
            ->groupBy('label')
            ->map(fn ($g) => ['value' => (int) $g->first()->value, 'approx' => (bool) $g->first()->approx]);

        // Today's snapshot, for the approx case below.
        $todaySnapshot = StatSnapshot::query()
            ->where('metric', StatSnapshot::TABLE_COUNT)
            ->where('captured_on', now()->toDateString())
            ->get()
            ->keyBy('label');

        foreach ($stats as $label => $s) {
            // Only compare like with like. Today's number falls back to
            // information_schema's estimate whenever the exact COUNT(*) exceeds its
            // time cap, and on the 13M-row tables that is the normal path — so
            // subtracting yesterday's exact snapshot from today's estimate reported
            // swings of +279,953 / -430,369 / -428,236 on a hub that had not
            // changed at all, next to a visibly flat sparkline.
            //
            // Requiring both sides exact fixed that but overcorrected: the three
            // big tables are ALWAYS approx, so they lost their delta permanently —
            // and those are precisely the ones where a stalled rollup matters. So
            // when the live number is an estimate, compare snapshot to snapshot
            // instead. Both sides then come from the same measurement method, which
            // is the actual requirement; exactness never was.
            $prev = $previous[$label] ?? null;

            if ($prev === null) {
                $stats[$label]['delta'] = null;

                continue;
            }

            if ($s['count'] !== null && empty($s['approx']) && ! $prev['approx']) {
                $stats[$label]['delta'] = $s['count'] - $prev['value'];

                continue;
            }

            // Estimate today: use today's stored snapshot, which was captured the
            // same way yesterday's was.
            $today = $todaySnapshot[$label] ?? null;

            $stats[$label]['delta'] = ($today && (bool) $today->approx === $prev['approx'])
                ? (int) $today->value - $prev['value']
                : null;
        }

        return $stats;
    }

    /** Full details for one identity (right-side panel, loaded on name click). */
    public function show($identity)
    {
        [$profile, $oversized] = $this->loadProfile((int) $identity);
        $basis = $this->basisFor((int) $identity);
        $credentialIds = $this->credentialIdsFor($profile);

        // AJAX (from the search panel) gets the bare partial; a direct visit to
        // the URL gets the full page with the shared nav/header wrapper.
        if (request()->ajax() || request()->wantsJson()) {
            return view('partials.profile-detail', compact('profile', 'basis', 'oversized', 'credentialIds'));
        }

        return view('profile', compact('profile', 'basis', 'oversized', 'credentialIds'));
    }

    /**
     * Credential number per credential-match id, for the credential checks table.
     *
     * The hub's credentials rollup carries only credential_match_id, which is a
     * row id in the CAMI source database and means nothing to a reader — the
     * number they recognise is credential_matches.credential_id (the certificate
     * number that was checked, e.g. "076908-1"). The hub never copied it, so it
     * is read back by primary key from the source database.
     *
     * Best-effort by design: the source DB is remote, and a credential number is
     * a label on a row that is already fully rendered. If the lookup fails the
     * column shows a dash rather than the page failing.
     *
     * @return array<int,string> credential_match_id => credential_id
     */
    private function credentialIdsFor(GpProfile $profile): array
    {
        $rollup = $profile->credentials ?? null;
        if (! is_array($rollup) || ! $rollup) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($c) => (int) ($c['credential_match_id'] ?? 0),
            $rollup,
        ))));

        if (! $ids) {
            return [];
        }

        // Bounded the same way the table itself is: an over-merged identity can
        // carry thousands of credential rows, and the view renders at most
        // PROFILE_ROW_CAP of them, so there is nothing to gain by asking the
        // source database for the rest.
        $ids = array_slice($ids, 0, self::PROFILE_ROW_CAP);

        try {
            return Cache::remember(
                // Keyed on the ids themselves, not their count: a rollup that
                // swapped one match for another would otherwise keep serving the
                // previous page's numbers for the rest of the TTL.
                'gpcami.credential-ids.' . md5(implode(',', $ids)),
                (int) config('gpcami.match_gate_ttl', 300),
                function () use ($ids) {
                    $rows = DB::connection(config('gpcami.source_connection'))
                        ->table('credential_matches')
                        ->whereIn('id', $ids)
                        ->pluck('credential_id', 'id');

                    return $rows->filter(fn ($v) => $v !== null && $v !== '')
                        ->map(fn ($v) => (string) $v)
                        ->all();
                },
            );
        } catch (\Throwable $e) {
            Log::warning('credential id lookup failed', [
                'identity' => $profile->identity_id, 'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Load a profile, leaving behind any rollup JSON column too big to hold in
     * memory. The over-merged fixture identities are extreme — identity 3
     * ("John Smith", 12,452 source records) carries a 69MB credentials blob and
     * a 38MB exclusions blob, which blew PHP's 128M limit on a plain find().
     *
     * @return array{0:GpProfile,1:array<string,float>} model + skipped column => MB
     */
    private function loadProfile(int $identityId): array
    {
        $conn = config('gpcami.connection');
        $table = config('gpcami.profile_table');
        $jsonCols = config('gpcami.profile_json');

        $oversized = [];
        try {
            $lengths = DB::connection($conn)->selectOne(
                'SELECT ' . implode(', ', array_map(fn ($c) => "OCTET_LENGTH(`$c`) AS `$c`", $jsonCols))
                . " FROM `$table` WHERE identity_id = ?",
                [$identityId],
            );
            foreach ($jsonCols as $c) {
                $bytes = (int) ($lengths->{$c} ?? 0);
                if ($bytes > self::MAX_JSON_BYTES) {
                    $oversized[$c] = round($bytes / 1048576, 1);
                }
            }
        } catch (\Throwable $e) {
            // Size probe failed — fall through and load everything as before.
        }

        if (! $oversized) {
            return [GpProfile::findOrFail($identityId), []];
        }

        $keep = array_values(array_diff(
            Schema::connection($conn)->getColumnListing($table),
            array_keys($oversized),
        ));

        return [GpProfile::select($keep)->findOrFail($identityId), $oversized];
    }

    /**
     * Exact row count for one stats table, on demand.
     *
     * The board shows an instant estimate for the big tables because an exact
     * COUNT(*) over 13M InnoDB rows takes seconds even on an idle hub. This is
     * the "count it properly" escape hatch behind each estimated card.
     */
    public function exactCount(string $table)
    {
        $tables = array_values(config('gpcami.stats_tables'));
        if (! in_array($table, $tables, true)) {
            // Direct JsonResponse for the same reason as matchJson() below.
            return response()->json([
                'error' => 'not_found',
                'message' => 'Unknown table.',
            ], 404);
        }

        try {
            $count = Cache::remember("gpcami.exact.$table", 900, function () use ($table) {
                return (int) DB::connection(config('gpcami.connection'))
                    ->selectOne("SELECT /*+ MAX_EXECUTION_TIME(30000) */ COUNT(*) c FROM `$table`")->c;
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'The hub did not finish counting in time.'], 504);
        }

        return response()->json(['table' => $table, 'count' => $count]);
    }

    /**
     * The raw match payload behind one credential check or exclusion hit.
     *
     * The hub only materialises summary columns (status, flags, link state), so
     * the actual scraper / exclusion-list JSON has to be read back from the CAMI
     * source DB. Read-only, one row by primary key.
     */
    public function matchJson(string $kind, int $id)
    {
        $src = config('gpcami.source_connection');

        // Only ids the hub actually links to an identity are readable. Without
        // this the route is a raw numeric cursor over credential_matches / matches
        // in the CAMI source DB — anyone who can reach the dashboard could walk
        // 1..N and dump match payloads for people they never looked up, which is
        // both more data and more sensitive data than the hub itself exposes.
        if (! $this->matchIsLinkedToIdentity($kind, $id)) {
            // An explicit JsonResponse, not abort(404). Routing /match/* through
            // the JSON exception renderer (so its errors stop being HTML) means
            // abort() now serialises the HttpException — and with APP_DEBUG on that
            // is ~50 stack frames and absolute source paths, returned on the gate's
            // most ordinary response. Returning the response directly never enters
            // the exception renderer at all.
            return response()->json([
                'error' => 'not_found',
                'message' => "No $kind match with id $id is linked to an identity.",
            ], 404);
        }

        try {
            if ($kind === 'credential') {
                $row = DB::connection($src)->selectOne(
                    'SELECT id, employee_id, registry, credential_id, license_type_id, type, status,
                            match_summary_status, match_summary_status_code, match_is_valid, `current`,
                            expiry_date, date_created, date_updated, date_resolved, match_context, `match`
                       FROM credential_matches WHERE id = ?',
                    [$id],
                );
                if (! $row) {
                    return response()->json(['error' => "Credential match $id is no longer in the source database."], 404);
                }
                $payload = array_diff_key((array) $row, ['match' => null]);
                $payload['match'] = self::withoutImages($this->decodeOrRaw($row->match));
            } else {
                $row = DB::connection($src)->selectOne(
                    'SELECT m.id, m.employee_id, m.exclusion_record_id, m.date_created, m.metadata,
                            m.is_ssn_match, m.is_npi_match, m.is_canonical_name_match,
                            m.is_upin_match, m.is_license_number_match,
                            e.exclusion_list_prefix, e.`match` AS record
                       FROM matches m
                       LEFT JOIN exclusion_records e ON e.id = m.exclusion_record_id
                      WHERE m.id = ?',
                    [$id],
                );
                if (! $row) {
                    return response()->json(['error' => "Exclusion match $id is no longer in the source database."], 404);
                }
                $payload = array_diff_key((array) $row, ['record' => null, 'metadata' => null]);
                $payload['metadata'] = self::withoutImages($this->decodeOrRaw($row->metadata));
                $payload['exclusion_record'] = self::withoutImages($this->decodeOrRaw($row->record));
            }
        } catch (\Throwable $e) {
            // The driver message can carry hostnames, schema and column names —
            // log it, don't ship it to the browser.
            Log::error('matchJson source query failed', ['kind' => $kind, 'id' => $id, 'exception' => $e->getMessage()]);

            return response()->json(['error' => 'Could not reach the CAMI source database.'], 502);
        }

        return response()->json($payload, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Is this source match id reachable through the hub at all? Credential ids are
     * linked via gp_identity_credential.credential_match_id, exclusion ids via
     * gp_identity_exclusion.match_id.
     *
     * COST, measured — an earlier version of this comment claimed a ~2ms skip scan,
     * which was wrong: neither column has its own index, and on the current data
     * the plan is type=ALL over ~3.21M rows, about 2.24s per request. That is the
     * price of the gate, and it is worth paying (without it the route is a numeric
     * cursor over the source DB), but it is not free. `gpdash:index-advisor` lists
     * the dedicated index that turns this into a point lookup — adding it is the
     * real fix, and it needs an operator because it ALTERs the shared hub.
     *
     * Results are cached briefly: the profile panel loads several match payloads
     * per view, and re-running a multi-second scan for each one is what made the
     * page feel broken. Link membership does not change within a page view.
     */
    private function matchIsLinkedToIdentity(string $kind, int $id): bool
    {
        [$table, $column] = $kind === 'credential'
            ? ['gp_identity_credential', 'credential_match_id']
            : ['gp_identity_exclusion', 'match_id'];

        try {
            return Cache::remember(
                "gpcami.match-linked.$kind.$id",
                (int) config('gpcami.match_gate_ttl', 300),
                fn () => DB::connection(config('gpcami.connection'))
                    ->table($table)->where($column, $id)->limit(1)->exists(),
            );
        } catch (\Throwable $e) {
            Log::error('matchJson link check failed', ['kind' => $kind, 'id' => $id, 'exception' => $e->getMessage()]);

            return false;   // fail closed
        }
    }

    /** Payload keys that hold a picture rather than information. */
    private const IMAGE_KEYS = ['image', 'images', 'screenshot', 'screenshots', 'photo', 'thumbnail'];

    /**
     * Replace embedded images in a match payload with a note saying one was there.
     *
     * Registry scrapers attach a screenshot of the page they read as base64
     * inside the match JSON — a single credential match carries 321,835
     * characters of PNG, and an exclusion record a data: URI of its own. Dumped
     * into the <pre> block the panel renders, that is a third of a megabyte of
     * unreadable text burying the fields the reviewer opened the payload for,
     * and it is sent over the wire on every "Match data" click.
     *
     * The note keeps the fact that a capture exists (and how big it was) without
     * carrying the capture itself. Matched by key name and by data: URI, so a
     * registry that names the field something new is still caught by its value.
     */
    private static function withoutImages(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_starts_with($value, 'data:image/') ? self::imageNote($value) : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_string($item) && in_array(mb_strtolower((string) $key), self::IMAGE_KEYS, true)
                ? ($item === '' ? $item : self::imageNote($item))
                : self::withoutImages($item);
        }

        return $out;
    }

    private static function imageNote(string $raw): string
    {
        return '[image omitted, ' . number_format(strlen($raw) / 1024, 1) . ' KB]';
    }

    /** Scraper payloads are usually JSON but not guaranteed to be. */
    private function decodeOrRaw(?string $raw)
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    /**
     * Why this identity looks the way it does: how each source record was
     * linked (gp_source_link), which rule picked each canonical value
     * (gp_survivorship_audit), and — when the links predate the merge that
     * joined them — the keys the members demonstrably share or disagree on.
     *
     * The shared/conflicting keys come from `gpdash:merge-basis` when it has run
     * for this identity, and are derived live otherwise. Precomputing matters
     * most for the identities the live path used to skip: above 500 links it
     * gave up, which is exactly where the question is worth asking.
     *
     * All the hub reads are indexed lookups by identity_id. Best-effort: a hub
     * problem degrades this section, it never breaks the profile.
     */
    private function basisFor(int $identityId): array
    {
        $conn = config('gpcami.connection');
        $out = [
            'links' => [], 'link_total' => 0, 'by_key' => [], 'weakest' => null,
            'needs_review' => 0, 'pinned' => 0, 'audit' => [], 'shared' => [],
            'conflicts' => [], 'basis_source' => null, 'basis_truncated' => false,
            'derive_skipped' => false, 'error' => null,
        ];

        try {
            $db = DB::connection($conn);

            $out['links'] = $db->select(
                'SELECT /*+ MAX_EXECUTION_TIME(10000) */
                        l.link_id, l.source_table, l.source_id, l.account_id, l.employeelist_id,
                        l.match_method, l.match_key, l.match_score, l.match_state, l.is_pinned,
                        l.linked_at, s.system_code
                   FROM gp_source_link l
                   LEFT JOIN gp_source_system s ON s.system_id = l.system_id
                  WHERE l.identity_id = ?
                  ORDER BY l.link_id
                  LIMIT ' . self::MAX_BASIS_ROWS,
                [$identityId],
            );
            $out['link_total'] = (int) $db->selectOne(
                'SELECT COUNT(*) c FROM gp_source_link WHERE identity_id = ?', [$identityId],
            )->c;

            foreach ($out['links'] as $l) {
                $out['by_key'][$l->match_key ?? 'unknown'] = ($out['by_key'][$l->match_key ?? 'unknown'] ?? 0) + 1;
                $score = $l->match_score === null ? null : (float) $l->match_score;
                if ($score !== null && ($out['weakest'] === null || $score < $out['weakest'])) {
                    $out['weakest'] = $score;
                }
                if ($l->match_state !== 'auto_match') {
                    $out['needs_review']++;
                }
                if ($l->is_pinned) {
                    $out['pinned']++;
                }
            }
            arsort($out['by_key']);

            $out['audit'] = $db->select(
                'SELECT /*+ MAX_EXECUTION_TIME(10000) */
                        attribute_name, surviving_value, rule_applied, source_link_id, decided_at
                   FROM gp_survivorship_audit
                  WHERE identity_id = ?
                  ORDER BY attribute_name
                  LIMIT ' . self::MAX_BASIS_ROWS,
                [$identityId],
            );

            // match_key is stamped when a row is first linked and is NOT rewritten
            // when a later dedup tier merges two identities — so a multi-record
            // identity whose links all say 'new' has no recorded merge basis.
            $keys = array_keys($out['by_key']);
            if (count($out['links']) > 1 && $keys === ['new']) {
                $this->attachDerivedBasis($out, $identityId);
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /** Stored basis if `gpdash:merge-basis` has run for this identity, live derive otherwise. */
    private function attachDerivedBasis(array &$out, int $identityId): void
    {
        if ($stored = MergeBasis::find($identityId)) {
            $out['shared'] = $stored->basis ?? [];
            $out['conflicts'] = $stored->conflicts ?? [];
            $out['basis_source'] = 'precomputed ' . $stored->computed_at?->diffForHumans();
            $out['basis_truncated'] = $stored->truncated;

            return;
        }

        if ($out['link_total'] > self::MAX_DERIVE_LINKS) {
            $out['derive_skipped'] = true;

            return;
        }

        $derived = app(\App\Services\MergeBasisDeriver::class)->derive($identityId);
        $out['shared'] = $derived['basis'];
        $out['conflicts'] = $derived['conflicts'];
        $out['basis_source'] = 'derived now';
        $out['basis_truncated'] = $derived['truncated'];
    }
}
