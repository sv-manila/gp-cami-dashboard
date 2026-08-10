<?php

namespace App\Services;

use App\Models\GpProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs a resolved search criterion against the hub and returns one page of
 * narrow profile rows.
 *
 * Two rules hold everywhere in here:
 *  - only the configured list columns are selected (a `SELECT *` page of
 *    gp_identity_profile can be hundreds of megabytes of JSON rollups);
 *  - every statement carries MAX_EXECUTION_TIME, so a non-sargable predicate
 *    fails in seconds with an explanation instead of hanging on 13M rows.
 */
class ProfileSearch
{
    /**
     * Web-request budget for an interactive search, in milliseconds.
     *
     * 8s, not 15s. A cold dashboard already spends ~7.5s on the stats board, so a
     * 15s search budget put a single request at ~22s against PHP's 30s limit — and
     * exceeding that is the uncatchable fatal this hint exists to prevent. It is
     * also 15s of unauthenticated 13.6M-row scanning per request. A search that
     * cannot answer in 8s needs narrowing, which is what explain() tells the user.
     */
    private const TIMEOUT_MS = 8000;

    /** Cap on identity ids collected from a lookup table before hydrating. */
    private const MAX_IDS = 2000;

    public function __construct(private SearchResolver $resolver) {}

    /**
     * @param  array<string,mixed>  $criteria  from SearchResolver::resolve()
     * @param  array{per_page?:int,cursor?:?string,prefix?:bool,dob?:?string,exclusions_only?:bool}  $opts
     * @return array{
     *   paginator:?\Illuminate\Pagination\CursorPaginator,
     *   matched_on:?string, error:?string, suggestions:array<int,array{last_name:string,n:int}>
     * }
     */
    public function run(array $criteria, array $opts = []): array
    {
        $out = ['paginator' => null, 'matched_on' => null, 'error' => null, 'suggestions' => []];

        try {
            [$query, $matchedOn] = $this->buildQuery($criteria, $opts);
        } catch (SearchInputException $e) {
            $out['error'] = $e->getMessage();

            return $out;
        }

        if ($query === null) {
            return $out;
        }

        $out['matched_on'] = $matchedOn;

        try {
            $out['paginator'] = $query
                ->orderByDesc('record_count')
                ->orderBy('identity_id')
                ->cursorPaginate(
                    (int) ($opts['per_page'] ?? config('gpcami.per_page', 50)),
                    ['*'],
                    'cursor',
                    $opts['cursor'] ?? null,
                );
        } catch (\Throwable $e) {
            $out['error'] = $this->explain($e, $criteria);

            return $out;
        }

        // Nothing found on an exact name — offer the surnames that do exist
        // under the same prefix before the user assumes the person is absent.
        if ($criteria['type'] === 'name' && $out['paginator']->isEmpty() && ! ($opts['prefix'] ?? false)) {
            $out['suggestions'] = $this->suggestSurnames($criteria['last'] ?? null);
        }

        return $out;
    }

    /**
     * @return array{0:?Builder,1:?string}
     *
     * @throws SearchInputException
     */
    private function buildQuery(array $criteria, array $opts): array
    {
        $timeoutMs = (int) ($opts['timeout_ms'] ?? self::TIMEOUT_MS);

        $base = fn () => GpProfile::query()
            ->selectRaw($this->hintedColumns($timeoutMs))
            ->when($opts['exclusions_only'] ?? false, fn ($q) => $q->where('has_active_exclusion', 1));

        switch ($criteria['type']) {
            case 'empty':
                return [null, null];

            case 'uuid':
                return [$base()->where('identity_uuid', $criteria['term']), 'identity_uuid'];

            case 'identity':
                return [$base()->where('identity_id', (int) $criteria['term']), 'identity_id'];

            case 'npi':
                return [$base()->where('npi', (int) preg_replace('/\D/', '', $criteria['term'])), 'NPI (idx_npi)'];

            case 'dea':
                // A DEA number reaches an identity two ways: as the canonical
                // survivorship winner on the profile, or as one of the
                // multi-valued identifiers that never won. Search both.
                $ids = $this->identifierIds('dea', $criteria['term']);

                return [
                    $base()->where(fn ($q) => $q
                        ->where('dea_number', $criteria['term'])
                        ->when($ids, fn ($w) => $w->orWhereIn('identity_id', $ids))),
                    'DEA (idx_dea + gp_identity_identifier)',
                ];

            case 'mmis':
                $ids = $this->identifierIds('mmis', $criteria['term']);

                return [$this->byIds($base(), $ids), 'MMIS (gp_identity_identifier)'];

            case 'license':
                return [
                    $this->byIds($base(), $this->licenseIds($criteria['term'], $criteria['state'] ?? null)),
                    'license number' . ($criteria['state'] ?? null ? ' + state' : '') . ' (idx_number_state)',
                ];

            case 'source':
                return [
                    $this->byIds($base(), $this->sourceIds($criteria['term'])),
                    'source employee id (gp_source_link.uq_source)',
                ];

            case 'ssn4':
                // Validate the term rather than querying whatever is left over.
                // Only one prefix and one qualifier are parsed, so a trailing token
                // ("ssn4:1234 last:Smith extra") stayed in the term as "1234 extra"
                // and matched nothing — a silent empty result that reads exactly
                // like "this person is not in the hub".
                if (! preg_match('/^\d{4}$/', $criteria['term'])) {
                    throw new SearchInputException(
                        'An SSN last-four search takes exactly four digits, for example "ssn4:6789 last:Smith". '
                        .'Received: "'.$criteria['term'].'".',
                    );
                }

                $last = $opts['last_name'] ?? null;
                if (! $last) {
                    throw new SearchInputException(
                        'An SSN last-four search needs a surname too — ssn_last_four is not indexed on its own. Try "ssn4:1234 last:Smith" or add the last name to the search box.',
                    );
                }

                return [
                    $base()->where('last_name', $last)->where('ssn_last_four', $criteria['term']),
                    'SSN last four + surname (idx_name_dob)',
                ];

            case 'numeric':
                // Ambiguous bare number: try it as an identity id and as a
                // source employee id at once.
                $ids = array_unique(array_merge([(int) $criteria['term']], $this->sourceIds($criteria['term'])));

                return [$this->byIds($base(), $ids), 'identity id or source employee id'];

            case 'name':
            default:
                return $this->nameQuery($base(), $criteria, $opts);
        }
    }

    /** @return array{0:Builder,1:string} */
    private function nameQuery(Builder $q, array $criteria, array $opts): array
    {
        $first = $criteria['first'] ?? null;
        $last = $criteria['last'] ?? null;
        $prefix = (bool) ($opts['prefix'] ?? false);
        $dob = $opts['dob'] ?? null;

        if (! $last && ! $first) {
            throw new SearchInputException('Enter a name or an identifier to search.');
        }

        if (! $last && ! $prefix) {
            // idx_name_dob is (last_name, first_name, date_of_birth) — a
            // first-name-only predicate cannot use it at all.
            throw new SearchInputException(
                'A first-name-only search has to scan the whole hub. Add a surname — the index leads with last name.',
            );
        }

        $cols = config('gpcami.columns');

        // Plain `=` on purpose — the name columns are utf8mb4_unicode_ci, so the
        // comparison is already case-insensitive. Wrapping them in LOWER() made
        // the predicate non-sargable and forced a full scan of the 13M-row table
        // (~128s per search); plain equality uses idx_name_dob and returns in
        // ~0.03s. LIKE 'x%' stays sargable on that same index, so prefix mode
        // costs a range read rather than the full scan a leading wildcard forces.
        if ($last) {
            $prefix
                ? $q->where($cols['last_name'], 'like', $this->escapeLike($last) . '%')
                : $q->where($cols['last_name'], '=', $last);
        }
        if ($first) {
            $prefix
                ? $q->where($cols['first_name'], 'like', $this->escapeLike($first) . '%')
                : $q->where($cols['first_name'], '=', $first);
        }
        if ($dob) {
            $q->where($cols['dob'], $dob);
        }

        return [$q, ($prefix ? 'name prefix' : 'exact name') . ($dob ? ' + date of birth' : '') . ' (idx_name_dob)'];
    }

    /**
     * Surnames that do exist under the same opening letters, so a typo reads as
     * a typo instead of "this person is not in the hub".
     *
     * @return array<int,array{last_name:string,n:int}>
     */
    private function suggestSurnames(?string $last): array
    {
        $stem = mb_substr(trim((string) $last), 0, 4);
        if (mb_strlen($stem) < 3) {
            return [];
        }

        try {
            $rows = DB::connection(config('gpcami.connection'))->select(
                'SELECT /*+ MAX_EXECUTION_TIME(5000) */ last_name, COUNT(*) n
                   FROM gp_identity_profile
                  WHERE last_name LIKE ?
                  GROUP BY last_name
                  ORDER BY n DESC
                  LIMIT 8',
                [$this->escapeLike($stem) . '%'],
            );
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($r) => ['last_name' => (string) $r->last_name, 'n' => (int) $r->n], $rows),
            fn ($r) => mb_strtolower($r['last_name']) !== mb_strtolower((string) $last),
        ));
    }

    /** @return array<int,int> */
    private function identifierIds(string $type, string $value): array
    {
        return $this->lookupIds(
            'SELECT /*+ MAX_EXECUTION_TIME(10000) */ DISTINCT identity_id
               FROM gp_identity_identifier
              WHERE id_type = ? AND id_value = ?
              LIMIT ' . self::MAX_IDS,
            [$type, $value],
        );
    }

    /** @return array<int,int> */
    private function licenseIds(string $number, ?string $state): array
    {
        $sql = 'SELECT /*+ MAX_EXECUTION_TIME(10000) */ DISTINCT identity_id
                  FROM gp_license
                 WHERE license_number = ?';
        $bindings = [$number];
        if ($state) {
            $sql .= ' AND certification_state = ?';
            $bindings[] = $state;
        }

        return $this->lookupIds($sql . ' LIMIT ' . self::MAX_IDS, $bindings);
    }

    /**
     * Source employee id -> identity. uq_source is (system_id, source_table,
     * source_id), so both leading columns are pinned to keep this a point read.
     *
     * @return array<int,int>
     */
    private function sourceIds(string $sourceId): array
    {
        return $this->lookupIds(
            'SELECT /*+ MAX_EXECUTION_TIME(10000) */ DISTINCT identity_id
               FROM gp_source_link
              WHERE system_id = ? AND source_table = ? AND source_id = ?
              LIMIT ' . self::MAX_IDS,
            [config('gpcami.default_system_id', 1), config('gpcami.source_table', 'employees'), (int) $sourceId],
        );
    }

    /** @return array<int,int> */
    private function lookupIds(string $sql, array $bindings): array
    {
        try {
            $rows = DB::connection(config('gpcami.connection'))->select($sql, $bindings);
        } catch (\Throwable $e) {
            Log::error('gp-cami identifier lookup failed', ['exception' => $e->getMessage()]);

            return [];
        }

        return array_map(fn ($r) => (int) $r->identity_id, $rows);
    }

    /** whereIn with no ids would match everything — force an empty result instead. */
    private function byIds(Builder $q, array $ids): Builder
    {
        return $ids ? $q->whereIn('identity_id', $ids) : $q->whereRaw('1 = 0');
    }

    private function escapeLike(string $v): string
    {
        return addcslashes(trim($v), '%_\\');
    }

    /**
     * The configured list columns, prefixed with the execution-time hint.
     *
     * The class contract above says every statement carries MAX_EXECUTION_TIME,
     * but the main search query was the one statement that did not — TIMEOUT_MS
     * was declared and never used. A predicate that escapes the sargability
     * guards (a one-character surname prefix, for instance) therefore scanned all
     * 13.6M rows with a filesort and ran for 116s until PHP's own
     * max_execution_time killed it, producing a 500 instead of the "narrow your
     * search" message explain() exists to return.
     *
     * MySQL only honours an optimizer hint immediately after SELECT, so it has to
     * ride on the column list rather than being appended elsewhere.
     *
     * The budget is a parameter because an export is not an interactive search: a
     * 10,000-row download legitimately outlives a 15s page budget, and applying
     * the interactive cap to it truncated real exports to an error marker.
     */
    private function hintedColumns(int $timeoutMs): string
    {
        $columns = (array) config('gpcami.list_columns');

        $quoted = implode(', ', array_map(
            // Config-owned identifiers, but quote defensively: these are
            // interpolated into raw SQL.
            fn ($c) => '`'.str_replace('`', '', (string) $c).'`',
            $columns,
        ));

        return '/*+ MAX_EXECUTION_TIME('.max(1000, $timeoutMs).') */ '.$quoted;
    }

    /** Driver messages carry hostnames and schema; translate, don't echo. */
    private function explain(\Throwable $e, array $criteria): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, '3024') || str_contains($msg, 'maximum statement execution time')) {
            return $criteria['type'] === 'name'
                ? 'Search timed out. Narrow it: a longer surname prefix, or add a first name or date of birth.'
                : 'Search timed out against the hub. Try a more specific term.';
        }

        Log::error('gp-cami search failed', ['type' => $criteria['type'] ?? '?', 'exception' => $msg]);

        return 'The hub query failed. See the application log for details.';
    }

    /**
     * Rows for an export: same criteria, no pagination, hard-capped.
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function stream(array $criteria, array $opts = [])
    {
        // An export gets its own, longer statement budget — see hintedColumns().
        $opts['timeout_ms'] = (int) config('gpcami.export_timeout_ms', 120000);

        [$query] = $this->buildQuery($criteria, $opts);

        if ($query === null) {
            return \Illuminate\Support\LazyCollection::empty();
        }

        $limit = (int) config('gpcami.export_limit', 10000);

        // The cap is enforced on the LazyCollection, not with ->limit().
        // lazy() pages the query with forPage(), which overwrites any limit
        // already set — so ->limit(10000)->lazy(500) streamed the entire result
        // set (a "Ma" prefix export produced 12,104 rows while the response still
        // advertised X-Export-Limit: 10000). take() on the lazy collection stops
        // pulling pages once the cap is reached.
        return $query
            ->orderByDesc('record_count')
            ->orderBy('identity_id')
            ->lazy(500)
            ->take($limit);
    }
}
