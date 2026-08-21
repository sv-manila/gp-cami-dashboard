<?php

namespace Tests\Unit;

use App\Http\Controllers\DashboardController;
use App\Services\SearchInputException;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards on the export and the dob filter. Both are private helpers on the
 * controller, reached by reflection so the behaviour can be pinned without a hub
 * connection — the alternative (a full HTTP test) needs the 13.6M-row hub and so
 * cannot run in CI.
 */
class ExportSafetyTest extends TestCase
{
    private function invokeStatic(string $method, ...$args): mixed
    {
        $m = new ReflectionMethod(DashboardController::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    /**
     * Excel and Sheets execute a cell beginning =, +, -, @ or a leading tab/CR.
     * Registry-scraped names are untrusted, so an export must not hand the
     * reviewer's spreadsheet something to run.
     */
    public function test_formula_prefixes_are_neutralised(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $dangerous) {
            $safe = $this->invokeStatic('csvSafe', $dangerous);
            $this->assertSame("'".$dangerous, $safe, "should be quoted: $dangerous");
        }
    }

    public function test_ordinary_values_are_untouched(): void
    {
        foreach (['Smith', 'O\'Brien', '1985-04-23', '1234567893', 'Ståhl'] as $ok) {
            $this->assertSame($ok, $this->invokeStatic('csvSafe', $ok), "should pass through: $ok");
        }
    }

    public function test_non_strings_and_empties_pass_through_unchanged(): void
    {
        $this->assertNull($this->invokeStatic('csvSafe', null));
        $this->assertSame('', $this->invokeStatic('csvSafe', ''));
        $this->assertSame(0, $this->invokeStatic('csvSafe', 0));
        $this->assertSame(42, $this->invokeStatic('csvSafe', 42));
    }

    public function test_valid_dob_is_accepted(): void
    {
        $this->assertSame('1985-04-23', $this->invokeStatic('normalizeDob', '1985-04-23'));
        $this->assertSame('1985-04-23', $this->invokeStatic('normalizeDob', '  1985-04-23  '));
    }

    public function test_absent_dob_is_null_not_an_error(): void
    {
        $this->assertNull($this->invokeStatic('normalizeDob', null));
        $this->assertNull($this->invokeStatic('normalizeDob', ''));
        $this->assertNull($this->invokeStatic('normalizeDob', '   '));
    }

    /**
     * A malformed dob used to be passed straight to the query, match nothing, and
     * render an ordinary "0 results" page — indistinguishable from "this person is
     * not in the hub" while the user's filter had actually been discarded.
     */
    public function test_malformed_dob_is_rejected_rather_than_silently_dropped(): void
    {
        foreach (['notadate', "' OR 1=1", '23/04/1985', '1985-4-3', '85-04-23', 'x1985-04-23'] as $bad) {
            try {
                $this->invokeStatic('normalizeDob', $bad);
                $this->fail("should have been rejected: $bad");
            } catch (\Throwable $e) {
                $this->assertInstanceOf(SearchInputException::class, $this->unwrap($e));
            }
        }
    }

    public function test_impossible_calendar_dates_are_rejected(): void
    {
        // createFromFormat rolls these over instead of failing, so a naive check
        // would accept 2026-13-45 and query a date the user never asked for.
        foreach (['2026-13-01', '2026-02-30', '2026-00-10', '2026-01-32'] as $bad) {
            try {
                $this->invokeStatic('normalizeDob', $bad);
                $this->fail("should have been rejected: $bad");
            } catch (\Throwable $e) {
                $this->assertInstanceOf(SearchInputException::class, $this->unwrap($e));
            }
        }
    }

    public function test_leap_day_validity_is_respected(): void
    {
        $this->assertSame('2024-02-29', $this->invokeStatic('normalizeDob', '2024-02-29'));

        try {
            $this->invokeStatic('normalizeDob', '2023-02-29');
            $this->fail('2023 is not a leap year');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(SearchInputException::class, $this->unwrap($e));
        }
    }

    /** Reflection wraps thrown exceptions in some PHP versions. */
    private function unwrap(\Throwable $e): \Throwable
    {
        return $e->getPrevious() instanceof SearchInputException ? $e->getPrevious() : $e;
    }
}
