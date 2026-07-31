<?php

namespace App\Http\Controllers;

use App\Models\GpProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /** Cap on rows pulled per basis query — blob identities have thousands of links. */
    private const MAX_BASIS_ROWS = 200;

    /** Rollup JSON columns bigger than this are not loaded (PHP memory_limit is 128M). */
    private const MAX_JSON_BYTES = 2097152;

    /** Above this many source records, skip the derived shared-key probe (seconds, not ms). */
    private const MAX_DERIVE_LINKS = 500;

    /** One page: Golden Profile Stats + name search (results list only). */
    public function index(Request $request)
    {
        $conn = config('gpcami.connection');
        $ttl  = (int) config('gpcami.cache_ttl', 60);

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

        $first = trim((string) $request->query('first_name', ''));
        $last  = trim((string) $request->query('last_name', ''));
        $searched = $request->has('first_name') || $request->has('last_name');

        $results = collect();
        $error = null;

        if ($searched && ($first !== '' || $last !== '')) {
            $table = config('gpcami.profile_table');
            try {
                // Capped like the stats counts: idx_name_dob leads with last_name,
                // so a first-name-only search can't use it and would scan 13M rows.
                // Fail fast with a hint instead of hanging the page.
                $results = GpProfile::byName($first, $last)
                    ->selectRaw("/*+ MAX_EXECUTION_TIME(15000) */ `$table`.*")
                    ->limit(500)
                    ->get();
            } catch (\Throwable $e) {
                $error = str_contains($e->getMessage(), '3024') || str_contains($e->getMessage(), 'maximum statement execution time')
                    ? 'Search timed out. A first-name-only search has to scan the whole hub — add a last name (the index is last name first).'
                    : 'gp-cami query failed: ' . $e->getMessage();
            }
        }

        return view('dashboard', compact('stats', 'first', 'last', 'searched', 'results', 'error'));
    }

    /** Full details for one identity (right-side panel, loaded on name click). */
    public function show($identity)
    {
        [$profile, $oversized] = $this->loadProfile((int) $identity);
        $basis = $this->basisFor((int) $identity);

        // AJAX (from the search panel) gets the bare partial; a direct visit to
        // the URL gets the full page with the shared nav/header wrapper.
        if (request()->ajax() || request()->wantsJson()) {
            return view('partials.profile-detail', compact('profile', 'basis', 'oversized'));
        }

        return view('profile', compact('profile', 'basis', 'oversized'));
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
            abort(404);
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
                $payload['match'] = $this->decodeOrRaw($row->match);
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
                $payload['metadata'] = $this->decodeOrRaw($row->metadata);
                $payload['exclusion_record'] = $this->decodeOrRaw($row->record);
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not reach the CAMI source database: ' . $e->getMessage()], 502);
        }

        return response()->json($payload, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
     * joined them — the keys the members demonstrably share.
     *
     * All three are indexed lookups by identity_id. Best-effort: a hub problem
     * degrades this section, it never breaks the profile.
     */
    private function basisFor(int $identityId): array
    {
        $conn = config('gpcami.connection');
        $out = [
            'links' => [], 'link_total' => 0, 'by_key' => [], 'weakest' => null,
            'needs_review' => 0, 'pinned' => 0, 'audit' => [], 'shared' => [],
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
            // Recover it by looking at what the members actually share.
            $keys = array_keys($out['by_key']);
            if (count($out['links']) > 1 && $keys === ['new']) {
                if ($out['link_total'] <= self::MAX_DERIVE_LINKS) {
                    $out['shared'] = $this->sharedKeys($db, $identityId);
                } else {
                    $out['derive_skipped'] = true;
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /** Key fields where every member row that has a value agrees on one value. */
    private function sharedKeys($db, int $identityId): array
    {
        $rows = $db->select(
            'SELECT /*+ MAX_EXECUTION_TIME(10000) */
                    p.npi, p.ssn_hash, p.ssn_last_four, p.dea_number, p.upin,
                    p.date_of_birth, p.first_name, p.last_name
               FROM gp_source_link l
               JOIN stg_person p
                 ON p.system_id = l.system_id
                AND p.source_table = l.source_table
                AND p.source_id = l.source_id
              WHERE l.identity_id = ?
              LIMIT ' . self::MAX_BASIS_ROWS,
            [$identityId],
        );
        if (count($rows) < 2) {
            return [];
        }

        $fields = [
            'npi' => fn ($r) => $r->npi,
            'ssn' => fn ($r) => $r->ssn_hash ? 'hash ' . substr($r->ssn_hash, 0, 10) . '…' : null,
            'dea' => fn ($r) => $r->dea_number,
            'upin' => fn ($r) => $r->upin,
            'name + dob' => fn ($r) => $r->date_of_birth
                ? mb_strtolower(trim($r->first_name . ' ' . $r->last_name)) . ' · ' . $r->date_of_birth
                : null,
        ];

        $shared = [];
        foreach ($fields as $label => $get) {
            $vals = array_filter(array_map($get, $rows), fn ($v) => $v !== null && $v !== '');
            $distinct = array_unique($vals);
            // Two or more members carry it and they all agree.
            if (count($vals) > 1 && count($distinct) === 1) {
                $shared[$label] = ['value' => reset($distinct), 'members' => count($vals)];
            }
        }

        // Licenses and DEA/MMIS identifiers live on child tables, and merging on
        // a shared license is the most common reason a set of 'new' links ended
        // up on one identity (mergeByLicense runs after enrich, post-link).
        $lic = $db->select(
            'SELECT /*+ MAX_EXECUTION_TIME(10000) */
                    spl.license_number, spl.certification_state,
                    COUNT(DISTINCT sp.stg_person_id) n
               FROM gp_source_link l
               JOIN stg_person sp
                 ON sp.system_id = l.system_id AND sp.source_table = l.source_table AND sp.source_id = l.source_id
               JOIN stg_person_license spl ON spl.stg_person_id = sp.stg_person_id
              WHERE l.identity_id = ?
              GROUP BY spl.license_number, spl.certification_state
             HAVING n > 1
              ORDER BY n DESC
              LIMIT 5',
            [$identityId],
        );
        foreach ($lic as $i => $row) {
            $shared['license' . ($i ? ' #' . ($i + 1) : '')] = [
                'value' => $row->license_number . ($row->certification_state ? ' · ' . $row->certification_state : ''),
                'members' => (int) $row->n,
            ];
        }

        $ids = $db->select(
            'SELECT /*+ MAX_EXECUTION_TIME(10000) */
                    spi.id_type, spi.id_value, COUNT(DISTINCT sp.stg_person_id) n
               FROM gp_source_link l
               JOIN stg_person sp
                 ON sp.system_id = l.system_id AND sp.source_table = l.source_table AND sp.source_id = l.source_id
               JOIN stg_person_identifier spi ON spi.stg_person_id = sp.stg_person_id
              WHERE l.identity_id = ?
              GROUP BY spi.id_type, spi.id_value
             HAVING n > 1
              ORDER BY n DESC
              LIMIT 5',
            [$identityId],
        );
        foreach ($ids as $row) {
            $shared[$row->id_type] = ['value' => $row->id_value, 'members' => (int) $row->n];
        }

        return $shared;
    }
}
