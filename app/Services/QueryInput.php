<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Scalar coercion for query-string input.
 *
 * Every filter on this dashboard arrives as a GET parameter and was read with a
 * plain `(string)` cast. PHP throws an ErrorException converting an array to a
 * string, and an ErrorException is not a SearchInputException, so `?dob[]=x`,
 * `?q[]=x`, `?last[]=x` and friends blew straight past both catch blocks and
 * rendered a 929KB debug page with a 500.
 *
 * Array input is a client mistake, not a server fault, so it is reported the same
 * way any other bad filter is — as a SearchInputException the existing handlers
 * already turn into a readable message (or a 422 on the export).
 */
class QueryInput
{
    /**
     * Read one query parameter as a trimmed string.
     *
     * @throws SearchInputException when the parameter is an array or object
     */
    public static function string(Request $request, string $key, string $default = ''): string
    {
        if (! $request->has($key)) {
            return $default;
        }

        $value = $request->query($key);

        if (is_array($value)) {
            throw new SearchInputException(
                "The \"$key\" parameter must be a single value, not a list."
            );
        }

        if (is_object($value)) {
            throw new SearchInputException("The \"$key\" parameter is not a valid value.");
        }

        return trim((string) $value);
    }

    /**
     * First non-empty of several parameters, for the older aliases of a field.
     *
     * @param  list<string>  $keys
     *
     * @throws SearchInputException
     */
    public static function firstString(Request $request, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = self::string($request, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Page sizes a list view will honour.
     *
     * A whitelist rather than a clamp: every list on this dashboard reads a hub
     * table whose rows are measured in kilobytes (and whose JSON rollups are
     * measured in megabytes), so `per_page` is a lever on a shared database
     * rather than a display preference.
     */
    public const PAGE_SIZES = [25, 50, 100, 200];

    /**
     * Page size for a list view, falling back to the caller's default.
     *
     * Unlike a filter, an unusable page size is not worth failing the page over
     * — it changes how many rows are shown, never which rows match, so there is
     * nothing to silently drop and nothing to mislead the reader about. Array
     * input (`?per_page[]=x`) takes the same fallback instead of raising.
     */
    public static function perPage(Request $request, int $default, string $key = 'per_page'): int
    {
        $value = $request->query($key);

        if (! is_scalar($value)) {
            return $default;
        }

        return in_array((int) $value, self::PAGE_SIZES, true) ? (int) $value : $default;
    }
}
