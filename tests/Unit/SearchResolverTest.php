<?php

namespace Tests\Unit;

use App\Services\SearchResolver;
use Tests\TestCase;

/**
 * SearchResolver parses the freeform search box, so it is the dashboard's entire
 * untrusted-input surface. It had no tests at all.
 *
 * Pure string handling — no database, so these run under the dead-port test config.
 */
class SearchResolverTest extends TestCase
{
    private SearchResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SearchResolver;
    }

    /** @return array<string,mixed> */
    private function resolve(string $q): array
    {
        return $this->resolver->resolve($q);
    }

    public function test_empty_and_whitespace_resolve_to_empty_not_a_scan(): void
    {
        // 'empty' is what stops buildQuery() from running at all. If blank input
        // fell through to a name search it would scan 13.6M rows.
        foreach (['', '   ', "\t", "\n  \n"] as $blank) {
            $this->assertSame('empty', $this->resolve($blank)['type'], 'blank input must not build a query');
        }
    }

    public function test_shape_detection(): void
    {
        $cases = [
            '1234567893' => 'npi',            // exactly 10 digits
            'AB1234563' => 'dea',             // 2 letters + 7 digits
            '41822' => 'numeric',             // ambiguous bare number
            '14473064-8a6c-11f1-8d8e-0800276de2c4' => 'uuid',
            'Smith' => 'name',
            'Smith, John' => 'name',
        ];

        foreach ($cases as $term => $expected) {
            $this->assertSame($expected, $this->resolve($term)['type'], "sniffing '$term'");
        }
    }

    public function test_explicit_prefixes_win_over_shape(): void
    {
        // '1234567893' looks like an NPI, but an explicit prefix must be honoured.
        $this->assertSame('identity', $this->resolve('id:1234567893')['type']);
        $this->assertSame('source', $this->resolve('emp:1234567893')['type']);
        $this->assertTrue($this->resolve('id:5')['explicit']);
        $this->assertFalse($this->resolve('5')['explicit']);
    }

    public function test_unknown_prefix_falls_back_to_a_name_search(): void
    {
        // Must not throw or resolve to a bogus type — the whole string is a name.
        $out = $this->resolve('bogus:whatever');
        $this->assertSame('name', $out['type']);
    }

    /**
     * The ssn4 search cannot run without a surname, and the error it raises
     * suggests exactly this syntax. Before this worked, that advice was impossible
     * to follow: only one prefix was parsed and it had to open the string.
     */
    public function test_last_qualifier_supplies_the_surname_an_ssn4_search_needs(): void
    {
        foreach (['ssn4:1234 last:Smith', 'last:Smith ssn4:1234'] as $q) {
            $out = $this->resolve($q);
            $this->assertSame('ssn4', $out['type'], $q);
            $this->assertSame('1234', $out['term'], $q);
            $this->assertSame('Smith', $out['last'], $q);
        }
    }

    public function test_last_qualifier_accepts_a_quoted_multiword_surname(): void
    {
        $out = $this->resolve('ssn4:1234 last:"Van Der Berg"');

        $this->assertSame('Van Der Berg', $out['last']);
        $this->assertSame('1234', $out['term']);
    }

    public function test_last_qualifier_alone_is_a_surname_search(): void
    {
        $out = $this->resolve('last:Smith');

        $this->assertSame('name', $out['type']);
        $this->assertSame('Smith', $out['last']);
    }

    public function test_last_qualifier_does_not_override_a_parsed_surname(): void
    {
        // A name term already yields a surname; the qualifier must not clobber it.
        $out = $this->resolve('Adkins, Paula');

        $this->assertSame('Adkins', $out['last']);
    }

    public function test_raw_is_preserved_for_the_export_filename_and_ui_echo(): void
    {
        $q = 'ssn4:1234 last:Smith';

        // 'raw' is what the export filename and the search box are rebuilt from,
        // so stripping the qualifier out of it would lose the user's own input.
        $this->assertSame($q, $this->resolve($q)['raw']);
    }

    public function test_sql_metacharacters_are_carried_as_data_not_structure(): void
    {
        // The resolver only classifies; it must never mangle or interpret these.
        // Bindings are what keep them safe downstream, so the value has to survive
        // intact rather than being silently rewritten.
        foreach (["O'Brien", 'Smith%', 'Smith_', 'a\\b', "x; DROP TABLE y--"] as $hostile) {
            $out = $this->resolve($hostile);
            $this->assertSame('name', $out['type'], $hostile);
            $this->assertStringContainsString(trim($hostile, '\\'), $out['raw']);
        }
    }

    public function test_long_and_unicode_input_does_not_blow_up(): void
    {
        $this->assertSame('name', $this->resolve(str_repeat('a', 10000))['type']);
        $this->assertSame('name', $this->resolve('Ståhl Ünicode 名前')['type']);
        $this->assertSame('name', $this->resolve('🙂')['type']);
    }

    public function test_prefix_matching_is_case_insensitive(): void
    {
        $this->assertSame('identity', $this->resolve('ID:5')['type']);
        $this->assertSame('npi', $this->resolve('NPI:1234567893')['type']);
    }
}
