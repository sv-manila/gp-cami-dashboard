<?php

namespace App\Http\Controllers;

use App\Models\AccountRollup;
use App\Models\GpProfile;
use App\Services\QueryInput;
use App\Services\SearchInputException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Account lens — the profile page's `accounts` rollup, read the other way round.
 *
 * A profile can already tell you which accounts a person appears under; nothing
 * could tell you who is in an account, which is how CAMI users actually think
 * about their data.
 *
 * gp_source_link.account_id has no index on the hub, so the account list comes
 * from the nightly rollup and the per-account member list is paged and degrades
 * with an explanation rather than hanging. `gpdash:index-advisor` prints the one
 * index that makes the drill-in a range read.
 */
class AccountController extends Controller
{
    /** Accounts per page. The rollup is a local table, so this page can count. */
    private const ACCOUNTS_PER_PAGE = 50;

    /** Members listed per page of an account, once the hub can page them. */
    private const MEMBERS_PER_PAGE = 100;

    /** Members listed in one shot when the hub cannot page them — the old cap. */
    private const MAX_MEMBERS = 200;

    /**
     * How deep the member list can be paged.
     *
     * Each page is its own OFFSET query against the hub. Even on the composite
     * index the discarded prefix is real work, so a link to page 900,000 is not
     * a free way to spend hub CPU; the largest account in the rollup is well
     * inside this.
     */
    private const MAX_MEMBER_PAGES = 100;

    public function index(Request $request)
    {
        try {
            return $this->renderIndex($request);
        } catch (SearchInputException $e) {
            return response($e->getMessage(), 422);
        }
    }

    private function renderIndex(Request $request)
    {
        $accounts = AccountRollup::query()
            ->when($request->filled('q'), function ($q) use ($request) {
                // QueryInput, not a raw cast: ?q[]=x raised an uncaught
                // ErrorException and rendered a debug page with a 500.
                $term = QueryInput::string($request, 'q');
                // Escape LIKE metacharacters: an unescaped '%' or '_' silently
                // turns a filter into a wildcard and matches the wrong accounts.
                $like = addcslashes($term, '%_\\');
                $q->where(fn ($w) => $w
                    ->where('account_name', 'like', '%' . $like . '%')
                    ->orWhere('account_id', (int) $term));
            })
            ->orderByDesc('identity_count')
            // Tie-break on the primary key. identity_count alone is not a total
            // order — two accounts sharing a count can come back in a different
            // order on each query, which is how a row appears twice on page 2 and
            // never at all on page 3.
            ->orderBy('account_id')
            ->paginate(QueryInput::perPage($request, self::ACCOUNTS_PER_PAGE))
            ->withQueryString();

        return view('accounts.index', [
            'accounts' => $accounts,
            'q'        => (string) $request->query('q', ''),
            'stale'    => $accounts->first()?->captured_at,
        ]);
    }

    public function show(Request $request, int $account)
    {
        $rollup = AccountRollup::find($account);
        $index = $this->accountIndex();

        // Paging needs a stable order, and a stable order needs an index that
        // provides one. MEASURED on this hub: the same member query costs 11.8s
        // unordered and never finishes ORDER BY identity_id — the sort is over
        // all 12.5M gp_source_link rows, so it hit the 20s statement budget and
        // returned nothing at all. An unordered LIMIT is therefore not a weaker
        // form of paging here, it is the only form the hub can serve: cutting
        // pages out of a scan whose order nothing pins would repeat some members
        // across pages and lose others entirely.
        //
        // gpdash:index-advisor asks for (account_id, identity_id) precisely so
        // this page can be paged — that index makes the drill-in a range read
        // already in identity_id order, no filesort involved. Until it exists,
        // serve the single capped page this page has always served, and say so.
        $paged = $index === 'ordered';
        $perPage = $paged ? QueryInput::perPage($request, self::MEMBERS_PER_PAGE) : self::MAX_MEMBERS;
        $page = $paged ? min(self::MAX_MEMBER_PAGES, max(1, (int) $request->query('page', 1))) : 1;

        // Without any account_id index the member lookup is a full scan of
        // gp_source_link (12.5M rows), so serving it for an account that does
        // not exist let any visitor burn hub CPU by walking made-up ids —
        // /accounts/999999999999 answered 200 after 4.1s.
        //
        // The rollup covers every account today (6 of 6 verified), but it is a
        // nightly snapshot, so treating "absent from the rollup" as "does not
        // exist" would 404 a genuinely new account. Fall back to a tightly bounded
        // existence probe instead: correct for a new account, and cheap enough that
        // probing invented ids is not a useful lever.
        if (! $rollup && $index === 'none' && ! $this->accountExists($account)) {
            abort(404);
        }

        $identities = collect();
        $error = null;
        $more = false;

        try {
            // One row past the page, so the pager knows whether a next page
            // exists without a COUNT(DISTINCT identity_id) — a second pass over
            // the same rows for a number nothing displays.
            $rows = DB::connection(config('gpcami.connection'))->select(
                'SELECT /*+ MAX_EXECUTION_TIME(20000) */ DISTINCT identity_id
                   FROM gp_source_link
                  WHERE account_id = ?'
                  . ($paged ? ' ORDER BY identity_id' : '')
                  . ' LIMIT ' . ($perPage + 1)
                  . ($paged ? ' OFFSET ' . (($page - 1) * $perPage) : ''),
                [$account],
            );
            $more = count($rows) > $perPage;
            $ids = array_map(fn ($r) => (int) $r->identity_id, array_slice($rows, 0, $perPage));

            // A paged list is displayed in the order it was cut on: sorting the
            // page by record_count instead would mean page 2 is not "the next
            // hundred by the ordering you can see", which reads as a shuffled
            // list rather than a paged one. The single unpaged page has no page
            // boundary to be consistent with, so it keeps leading with the
            // heaviest identities.
            $identities = $ids
                ? GpProfile::query()->forList()->whereIn('identity_id', $ids)
                    ->when($paged,
                        fn ($q) => $q->orderBy('identity_id'),
                        fn ($q) => $q->orderByDesc('record_count')->orderBy('identity_id'))
                    ->get()
                : collect();
        } catch (\Throwable $e) {
            Log::error('account member query failed', ['account' => $account, 'exception' => $e->getMessage()]);
            $identities = collect();
            $more = false;
            // Do not print the --apply command here. This page has no
            // authentication, and that command ALTERs the shared hub; telling any
            // visitor to run it makes a schema change look like a self-service fix.
            $error = $index === 'none'
                ? 'This account\'s members could not be listed in time: gp_source_link.account_id is not '
                    . 'indexed, so the lookup is a full scan. Ask an operator to review the index advisor.'
                : 'The hub did not return this account\'s members in time.';
        }

        $members = (new Paginator($identities, $perPage, $page, [
            'path'  => $request->url(),
            'query' => $request->query(),
        ]))->hasMorePagesWhen($paged && $more && $page < self::MAX_MEMBER_PAGES);

        return view('accounts.show', [
            'account'    => $account,
            'rollup'     => $rollup,
            'members'    => $members,
            'index'      => $index,
            'paged'      => $paged,
            'truncated'  => ! $paged && $more,
            'depthCap'   => $paged && $more && $page >= self::MAX_MEMBER_PAGES,
            'maxMembers' => $paged ? self::MAX_MEMBER_PAGES * $perPage : self::MAX_MEMBERS,
            'error'      => $error,
        ]);
    }

    /**
     * Does this account exist at all? Only consulted when the nightly rollup has
     * no row for it and no index makes the lookup cheap.
     *
     * Short statement budget on purpose: one row is enough to answer, and a hard
     * cap keeps a walk through invented ids from costing more than it should. A
     * timeout is treated as "not found" — the alternative is serving a page whose
     * member list could not be produced anyway — but it is logged, so a wave of
     * false 404s is visible rather than silent. Results are cached so repeated
     * probes of the same id cost nothing.
     */
    private function accountExists(int $account): bool
    {
        return Cache::remember("gpcami.account-exists.$account", 600, function () use ($account) {
            try {
                return DB::connection(config('gpcami.connection'))->selectOne(
                    'SELECT /*+ MAX_EXECUTION_TIME(5000) */ 1 AS hit
                       FROM gp_source_link WHERE account_id = ? LIMIT 1',
                    [$account],
                ) !== null;
            } catch (\Throwable $e) {
                Log::warning('account existence probe did not complete; treating as not found', [
                    'account' => $account,
                    'exception' => $e->getMessage(),
                ]);

                return false;
            }
        });
    }

    /**
     * Which index the hub has on gp_source_link.account_id, and therefore what
     * this page can serve:
     *
     *  - 'ordered' — (account_id, identity_id): a range read already in member
     *                order, so the list can be paged with no filesort.
     *  - 'lookup'  — account_id alone: the drill-in is a range read, but putting
     *                it in order means sorting every link in the account, which
     *                on the largest one is 12.4M rows. Capped, not paged.
     *  - 'none'    — no index: the drill-in is a full scan. Capped, not paged.
     */
    private function accountIndex(): string
    {
        try {
            $rows = DB::connection(config('gpcami.connection'))->select(
                // Aliased in lower case on purpose: information_schema returns
                // its own column names upper-cased, so $r->index_name was an
                // undefined property and this page 500'd.
                'SELECT index_name AS idx, seq_in_index AS seq, column_name AS col
                   FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND seq_in_index <= 2',
                ['gp_source_link'],
            );
        } catch (\Throwable $e) {
            return 'none';
        }

        $byIndex = [];
        foreach ($rows as $r) {
            $byIndex[$r->idx][(int) $r->seq] = $r->col;
        }

        $found = 'none';
        foreach ($byIndex as $columns) {
            if (($columns[1] ?? null) !== 'account_id') {
                continue;
            }
            if (($columns[2] ?? null) === 'identity_id') {
                return 'ordered';
            }
            $found = 'lookup';
        }

        return $found;
    }
}
