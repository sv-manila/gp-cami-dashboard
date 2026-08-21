<?php

namespace Tests\Unit;

use App\Services\QueryInput;
use App\Services\SearchInputException;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Array query input used to reach a `(string)` cast, raise an ErrorException (not a
 * SearchInputException), sail past both catch blocks, and render a 929KB debug page
 * behind a 500 — on `?dob[]=x`, `?q[]=x`, `?last[]=x` and every other filter.
 */
class QueryInputTest extends TestCase
{
    private function request(array $query): Request
    {
        return Request::create('/', 'GET', $query);
    }

    public function test_array_input_is_a_reportable_input_error(): void
    {
        $this->expectException(SearchInputException::class);

        QueryInput::string($this->request(['dob' => ['x']]), 'dob');
    }

    public function test_the_message_names_the_offending_parameter(): void
    {
        try {
            QueryInput::string($this->request(['q' => ['a', 'b']]), 'q');
            $this->fail('should have thrown');
        } catch (SearchInputException $e) {
            $this->assertStringContainsString('"q"', $e->getMessage());
            $this->assertStringContainsString('single value', $e->getMessage());
        }
    }

    public function test_scalars_are_trimmed_and_stringified(): void
    {
        $r = $this->request(['q' => '  Smith  ', 'n' => '42']);

        $this->assertSame('Smith', QueryInput::string($r, 'q'));
        $this->assertSame('42', QueryInput::string($r, 'n'));
    }

    public function test_absent_parameter_returns_the_default(): void
    {
        $r = $this->request([]);

        $this->assertSame('', QueryInput::string($r, 'q'));
        $this->assertSame('fallback', QueryInput::string($r, 'q', 'fallback'));
    }

    public function test_present_but_empty_is_an_empty_string_not_the_default(): void
    {
        // An explicitly blank filter is a real state — "cleared" is not "absent".
        $this->assertSame('', QueryInput::string($this->request(['q' => '']), 'q', 'fallback'));
    }

    public function test_first_string_walks_the_aliases_in_order(): void
    {
        $r = $this->request(['last_name' => 'Jones']);
        $this->assertSame('Jones', QueryInput::firstString($r, ['last', 'last_name']));

        $r2 = $this->request(['last' => 'Smith', 'last_name' => 'Jones']);
        $this->assertSame('Smith', QueryInput::firstString($r2, ['last', 'last_name']));
    }

    public function test_first_string_rejects_an_array_in_any_alias(): void
    {
        $this->expectException(SearchInputException::class);

        QueryInput::firstString($this->request(['last_name' => ['x']]), ['last', 'last_name']);
    }
}
