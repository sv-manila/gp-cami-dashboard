<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Works out why the source records under one identity ended up together — and
 * where they disagree.
 *
 * The hub cannot answer this itself. `gp_source_link.match_key` is stamped when
 * a row is first linked and is NOT rewritten when a later dedup tier merges two
 * identities, so a multi-record identity whose links all say 'new' has no
 * recorded merge basis. Recover it by looking at what the members demonstrably
 * share (basis) and where they demonstrably differ (conflicts — the signal that
 * a merge was wrong).
 */
class MergeBasisDeriver
{
    /** Members sampled per identity. The over-merged fixtures have thousands. */
    public const MAX_MEMBERS = 200;

    private ConnectionInterface $db;

    /**
     * Takes a connection *name*, not a ConnectionInterface: type-hinting the
     * interface makes the container inject the app's default connection (the
     * dashboard's own sqlite file), and every query here silently ran against
     * the wrong database.
     */
    public function __construct(?string $connection = null)
    {
        $this->db = DB::connection($connection ?? config('gpcami.connection'));
    }

    /**
     * @return array{
     *   basis: array<string,array{value:string,members:int}>,
     *   conflicts: array<string,array{values:array<int,string>,members:int}>,
     *   member_count: int,
     *   truncated: bool
     * }
     */
    public function derive(int $identityId): array
    {
        $rows = $this->db->select(
            'SELECT /*+ MAX_EXECUTION_TIME(20000) */
                    p.npi, p.ssn_hash, p.ssn_last_four, p.dea_number, p.upin,
                    p.date_of_birth, p.first_name, p.last_name, p.state
               FROM gp_source_link l
               JOIN stg_person p
                 ON p.system_id = l.system_id
                AND p.source_table = l.source_table
                AND p.source_id = l.source_id
              WHERE l.identity_id = ?
              LIMIT ' . (self::MAX_MEMBERS + 1),
            [$identityId],
        );

        $truncated = count($rows) > self::MAX_MEMBERS;
        if ($truncated) {
            array_pop($rows);
        }

        $out = [
            'basis' => [], 'conflicts' => [],
            'member_count' => count($rows), 'truncated' => $truncated,
        ];
        if (count($rows) < 2) {
            return $out;
        }

        foreach ($this->personFields() as $label => $get) {
            $values = array_filter(array_map($get, $rows), fn ($v) => $v !== null && $v !== '');
            if (count($values) < 2) {
                continue;   // only one member carries it — neither shared nor conflicting
            }
            $distinct = array_values(array_unique($values));

            if (count($distinct) === 1) {
                $out['basis'][$label] = [
                    'value' => $this->displayValue($label, $distinct[0], $distinct),
                    'members' => count($values),
                ];
            } else {
                // Two members that both carry a high-precision key and disagree
                // on it should not be the same person.
                $out['conflicts'][$label] = [
                    'values' => array_map(
                        fn ($v) => $this->displayValue($label, $v, $distinct),
                        array_slice($distinct, 0, 6),
                    ),
                    'members' => count($values),
                ];
            }
        }

        foreach ($this->sharedChildKeys($identityId) as $label => $hit) {
            $out['basis'][$label] = $hit;
        }

        return $out;
    }

    /**
     * What the page (and the stored snapshot) is allowed to show for a value.
     *
     * ssn_hash never leaves this method intact. It is sha512(ssn + a key shared
     * with CAMI) over a 9-digit keyspace — about 2^30 possibilities — so any
     * meaningful prefix identifies the SSN outright: the previous 10 hex
     * characters were 40 bits, comfortably more than enough to pin one candidate
     * and recover the number by brute force once the key is known. A 128-row
     * sample of these values was already persisted in sqlite.
     *
     * The reader only needs to know WHETHER members share the SSN, and when they
     * disagree, HOW MANY distinct ones there are. Stable per-identity letters give
     * both without leaking any part of the digest.
     *
     * @param  list<string>  $distinct  every distinct value for this label
     */
    private function displayValue(string $label, string $value, array $distinct): string
    {
        if ($label !== 'ssn') {
            return $value;
        }

        if (count($distinct) === 1) {
            return 'same (not shown)';
        }

        $index = array_search($value, $distinct, true);

        return 'SSN '.chr(65 + (int) $index);
    }

    /**
     * Fields read straight off the staged member rows. Names are normalised so
     * casing and padding differences don't read as a conflict.
     *
     * @return array<string, callable(object):?string>
     */
    private function personFields(): array
    {
        return [
            'npi'        => fn ($r) => $r->npi ? (string) $r->npi : null,
            // Full hash on purpose — it is the grouping key, and truncating it here
            // would merge distinct SSNs. displayValue() redacts it before anything
            // is rendered or persisted.
            'ssn'        => fn ($r) => $r->ssn_hash ?: null,
            'dea'        => fn ($r) => $r->dea_number,
            'upin'       => fn ($r) => $r->upin,
            'date of birth' => fn ($r) => $r->date_of_birth,
            'name'       => fn ($r) => mb_strtolower(trim($r->first_name . ' ' . $r->last_name)) ?: null,
            'name + dob' => fn ($r) => $r->date_of_birth
                ? mb_strtolower(trim($r->first_name . ' ' . $r->last_name)) . ' · ' . $r->date_of_birth
                : null,
        ];
    }

    /**
     * Keys that live on the staged child tables. Merging on a shared license is
     * the most common reason a set of 'new' links ends up on one identity —
     * mergeByLicense runs after enrich, post-link.
     *
     * @return array<string,array{value:string,members:int}>
     */
    private function sharedChildKeys(int $identityId): array
    {
        $shared = [];

        $licenses = $this->db->select(
            'SELECT /*+ MAX_EXECUTION_TIME(20000) */
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
        foreach ($licenses as $i => $row) {
            $shared['license' . ($i ? ' #' . ($i + 1) : '')] = [
                'value' => $row->license_number . ($row->certification_state ? ' · ' . $row->certification_state : ''),
                'members' => (int) $row->n,
            ];
        }

        $identifiers = $this->db->select(
            'SELECT /*+ MAX_EXECUTION_TIME(20000) */
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
        foreach ($identifiers as $row) {
            $shared[$row->id_type] = ['value' => $row->id_value, 'members' => (int) $row->n];
        }

        return $shared;
    }
}
