<?php

namespace Tests\Unit;

use App\Http\Controllers\DashboardController;
use App\Services\QueryInput;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Paging inputs and the match-payload image scrubber.
 *
 * Both are the parts of the paging work that can be pinned without a hub
 * connection: the page-size whitelist is what stops ?per_page from being a lever
 * on a shared 13.6M-row database, and the scrubber is what keeps a third of a
 * megabyte of base64 PNG out of a payload the reviewer opened to read six
 * fields.
 */
class PaginationInputTest extends TestCase
{
    private function request(array $query): Request
    {
        return Request::create('/', 'GET', $query);
    }

    private function scrub(mixed $value): mixed
    {
        $m = new ReflectionMethod(DashboardController::class, 'withoutImages');
        $m->setAccessible(true);

        return $m->invoke(null, $value);
    }

    public function test_whitelisted_page_sizes_are_honoured(): void
    {
        foreach (QueryInput::PAGE_SIZES as $size) {
            $this->assertSame($size, QueryInput::perPage($this->request(['per_page' => (string) $size]), 50));
        }
    }

    /**
     * Anything off the list falls back rather than being clamped. A clamp would
     * turn ?per_page=100000 into the largest allowed page, which is exactly the
     * request the whitelist exists to refuse.
     */
    public function test_unusable_page_sizes_fall_back_to_the_default(): void
    {
        foreach (['0', '-10', '10000', 'fifty', '', '75'] as $bad) {
            $this->assertSame(50, QueryInput::perPage($this->request(['per_page' => $bad]), 50), "rejected: $bad");
        }

        $this->assertSame(40, QueryInput::perPage($this->request([]), 40));
    }

    /**
     * Array input takes the fallback instead of raising: a page size changes how
     * many rows are shown, never which rows match, so there is no filter being
     * silently dropped and nothing to mislead the reader about.
     */
    public function test_array_page_size_does_not_raise(): void
    {
        $this->assertSame(50, QueryInput::perPage($this->request(['per_page' => ['100']]), 50));
    }

    public function test_named_image_keys_are_replaced_with_a_note(): void
    {
        $payload = ['registry' => 'nursys', 'image' => str_repeat('A', 2048), 'status' => 'Active'];
        $scrubbed = $this->scrub($payload);

        $this->assertSame('nursys', $scrubbed['registry']);
        $this->assertSame('Active', $scrubbed['status']);
        $this->assertSame('[image omitted, 2.0 KB]', $scrubbed['image']);
    }

    /** A registry that names the field something else is caught by the value. */
    public function test_data_uris_are_replaced_wherever_they_appear(): void
    {
        $payload = ['capture' => 'data:image/png;base64,' . str_repeat('B', 1024)];
        $this->assertStringStartsWith('[image omitted,', $this->scrub($payload)['capture']);
    }

    public function test_nested_payloads_are_scrubbed(): void
    {
        $payload = ['details' => [['screenshot' => str_repeat('C', 512), 'action_date' => '2024-01-01']]];
        $scrubbed = $this->scrub($payload);

        $this->assertSame('[image omitted, 0.5 KB]', $scrubbed['details'][0]['screenshot']);
        $this->assertSame('2024-01-01', $scrubbed['details'][0]['action_date']);
    }

    /**
     * The scrubber runs over every match payload, including the ones that are a
     * bare string or were never JSON at all.
     */
    public function test_non_image_payloads_pass_through_unchanged(): void
    {
        $this->assertSame('plain text', $this->scrub('plain text'));
        $this->assertNull($this->scrub(null));
        $this->assertSame(['image' => ''], $this->scrub(['image' => '']));
        $this->assertSame(['image_count' => 3], $this->scrub(['image_count' => 3]));
    }
}
