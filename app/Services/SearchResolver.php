<?php

namespace App\Services;

/**
 * Turns one free-text search box into a typed query against the hub.
 *
 * Support work almost never starts from a name — it starts from an employee id
 * in a ticket, an NPI on a roster, or a licence number on a scraper error. The
 * resolver recognises those shapes so the user does not have to know which of
 * six tables holds them.
 *
 * An explicit prefix always wins (`npi:1234567893`); otherwise the shape of the
 * term decides, and anything unrecognised falls back to a name search.
 */
class SearchResolver
{
    /** Prefix => criteria type. Also the documented syntax shown in the UI. */
    public const PREFIXES = [
        'id'    => 'identity',
        'uuid'  => 'uuid',
        'npi'   => 'npi',
        'dea'   => 'dea',
        'mmis'  => 'mmis',
        'lic'   => 'license',
        'emp'   => 'source',
        'ssn4'  => 'ssn4',
        'name'  => 'name',
    ];

    /**
     * @return array{
     *   type:string, term:string, raw:string,
     *   first?:?string, last?:?string, state?:?string, explicit:bool
     * }
     */
    public function resolve(string $query): array
    {
        $raw = trim($query);
        if ($raw === '') {
            return ['type' => 'empty', 'term' => '', 'raw' => '', 'explicit' => false];
        }

        // Pull a `last:<surname>` qualifier out before prefix detection.
        //
        // Only one prefix is matched, and it must open the string, so
        // "ssn4:1234 last:Smith" previously resolved to type=ssn4 with the term
        // "1234 last:Smith" — the surname was never extracted and the search threw
        // "needs a surname too", whose own suggested remedy was that exact syntax.
        // An ssn4 search cannot run without a surname, so the documented form has
        // to work.
        $lastQualifier = null;
        $stripped = preg_replace_callback(
            // Requires a non-empty value: a bare "last:" is not a qualifier, and
            // treating it as one produced a surname search for the literal "last:".
            '/(?:^|\s)last\s*:\s*("[^"]*[^"\s][^"]*"|[^\s"]+)/i',
            function ($m) use (&$lastQualifier) {
                $value = trim($m[1], '"');
                // First qualifier wins; a second is left in place so it surfaces as
                // an unparsed leftover rather than silently overriding the first.
                $lastQualifier ??= $value;

                return ' ';
            },
            $raw,
            1,
        );

        if ($lastQualifier !== null) {
            $raw = trim(preg_replace('/\s+/', ' ', $stripped));

            // `last:Smith` on its own is a surname search. Built directly rather
            // than through the name parser: "last:Van Der Berg" would otherwise be
            // split into first=Van / last=Berg, losing the surname the user pinned.
            // The previous `+ ['last' => …]` was a no-op, because build() had
            // already set that key.
            if ($raw === '') {
                $criteria = $this->build('name', $lastQualifier, $query, true);
                $criteria['last'] = $lastQualifier;
                $criteria['first'] = null;

                return $criteria;
            }
        }

        if (preg_match('/^([a-z0-9]+)\s*:\s*(.+)$/i', $raw, $m)) {
            $prefix = mb_strtolower($m[1]);
            if (isset(self::PREFIXES[$prefix])) {
                return $this->withLast(
                    $this->build(self::PREFIXES[$prefix], trim($m[2]), $query, true),
                    $lastQualifier,
                );
            }
        }

        return $this->withLast($this->build($this->sniff($raw), $raw, $query, false), $lastQualifier);
    }

    /**
     * Attach an explicit `last:` qualifier.
     *
     * The qualifier WINS over a surname the parser inferred from the term. The
     * precedence was the other way round, which is backwards: "Smith last:Jones"
     * searched Smith and silently ignored Jones, so the one part of the query the
     * user was unambiguous about was the part discarded.
     *
     * @param  array<string,mixed>  $criteria
     * @return array<string,mixed>
     */
    private function withLast(array $criteria, ?string $last): array
    {
        if ($last !== null) {
            $criteria['last'] = $last;
        }

        return $criteria;
    }

    /** Shape-based detection for an unprefixed term. */
    private function sniff(string $term): string
    {
        // identity_uuid — char(36), unique index.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $term)) {
            return 'uuid';
        }
        // NPI is always exactly 10 digits.
        if (preg_match('/^\d{10}$/', $term)) {
            return 'npi';
        }
        // DEA: two letters then seven digits (registrant + check digit).
        if (preg_match('/^[A-Z]{2}\d{7}$/i', $term)) {
            return 'dea';
        }
        // A bare number that is not an NPI is ambiguous between an identity id
        // and a source employee id. Neither guess is safe, so search both and
        // let the results page show which one hit.
        if (preg_match('/^\d{1,9}$/', $term)) {
            return 'numeric';
        }

        return 'name';
    }

    /** @return array<string,mixed> */
    private function build(string $type, string $term, string $raw, bool $explicit): array
    {
        $out = ['type' => $type, 'term' => $term, 'raw' => $raw, 'explicit' => $explicit];

        if ($type === 'license') {
            // "12345/CA" or "12345 CA" — the state half is optional but makes
            // the lookup a true point read on idx_number_state.
            if (preg_match('#^(.+?)\s*[/ ]\s*([A-Za-z]{2})$#', $term, $m)) {
                $out['term'] = trim($m[1]);
                $out['state'] = mb_strtoupper($m[2]);
            } else {
                $out['state'] = null;
            }
        }

        if ($type === 'name') {
            [$first, $last] = $this->splitName($term);
            $out['first'] = $first;
            $out['last'] = $last;
        }

        return $out;
    }

    /**
     * "Smith, John" | "John Smith" | "Smith" -> [first, last].
     *
     * A single token is treated as a last name: idx_name_dob leads with
     * last_name, so that is the half that can actually use an index.
     *
     * @return array{0:?string,1:?string}
     */
    public function splitName(string $term): array
    {
        $term = trim(preg_replace('/\s+/', ' ', $term));
        if ($term === '') {
            return [null, null];
        }

        if (str_contains($term, ',')) {
            [$last, $first] = array_pad(array_map('trim', explode(',', $term, 2)), 2, null);

            return [$first ?: null, $last ?: null];
        }

        $parts = explode(' ', $term);
        if (count($parts) === 1) {
            return [null, $parts[0]];
        }

        // Everything before the final token is the given name(s); the last token
        // is the surname. Middle names are not searched, so they are dropped.
        $last = array_pop($parts);

        return [$parts[0], $last];
    }
}
