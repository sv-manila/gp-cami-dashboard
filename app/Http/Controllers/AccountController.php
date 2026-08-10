<?php

namespace App\Http\Controllers;

use App\Models\AccountRollup;
use App\Models\GpProfile;
use App\Services\QueryInput;
use App\Services\SearchInputException;
use Illuminate\Http\Request;
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
 * from the nightly rollup and the per-account member list is capped and degrades
 * with an explanation rather than hanging. `gpdash:index-advisor` prints the one
 * index that makes the drill-in a range read.
 */
class AccountController extends Controller
{
    /** Members listed per account without the account_id index. */
    private const MAX_MEMBERS = 200;

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
            ->limit(200)
            ->get();

        return view('accounts.index', [
            'accounts' => $accounts,
            'q'        => (string) $request->query('q', ''),
            'stale'    => $accounts->first()?->captured_at,
        ]);
    }

    public function show(int $account)
    {
        $rollup = AccountRollup::find($account);
        $indexed = $this->accountIndexExists();

        // Without the account_id index the member lookup is a full scan of
        // gp_source_link (12.5M rows, ~4s), so serving it for an account that does
        // not exist let any visitor burn hub CPU by walking made-up ids —
        // /accounts/999999999999 answered 200 after 4.1s.
        //
        // The rollup covers every account today (6 of 6 verified), but it is a
        // nightly snapshot, so treating "absent from the rollup" as "does not
        // exist" would 404 a genuinely new account. Fall back to a tightly bounded
        // existence probe instead: correct for a new account, and cheap enough that
        // probing invented ids is not a useful lever.
        if (! $rollup && ! $indexed && ! $this->accountExists($account)) {
            abort(404);
        }

        $identities = [];
        $error = null;
        $truncated = false;

        try {
            $rows = DB::connection(config('gpcami.connection'))->select(
                'SELECT /*+ MAX_EXECUTION_TIME(20000) */ DISTINCT identity_id
                   FROM gp_source_link
                  WHERE account_id = ?
                  LIMIT ' . (self::MAX_MEMBERS + 1),
                [$account],
            );
            $truncated = count($rows) > self::MAX_MEMBERS;
            $ids = array_map(fn ($r) => (int) $r->identity_id, array_slice($rows, 0, self::MAX_MEMBERS));

            $identities = $ids
                ? GpProfile::query()->forList()->whereIn('identity_id', $ids)
                    ->orderByDesc('record_count')->orderBy('identity_id')->get()
                : collect();
        } catch (\Throwable $e) {
            Log::error('account member query failed', ['account' => $account, 'exception' => $e->getMessage()]);
            $identities = collect();
            // Do not print the --apply command here. This page has no
            // authentication, and that command ALTERs the shared hub; telling any
            // visitor to run it makes a schema change look like a self-service fix.
            $error = $indexed
                ? 'The hub did not return this account\'s members in time.'
                : 'This account\'s members could not be listed in time: gp_source_link.account_id is not '
                    . 'indexed, so the lookup is a full scan. Ask an operator to review the index advisor.';
        }

        return view('accounts.show', [
            'account'    => $account,
            'rollup'     => $rollup,
            'identities' => $identities,
            'truncated'  => $truncated,
            'indexed'    => $indexed,
            'maxMembers' => self::MAX_MEMBERS,
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

    /** Whether the drill-in is a range read or a full scan, so the page can say which. */
    private function accountIndexExists(): bool
    {
        try {
            return (int) DB::connection(config('gpcami.connection'))->selectOne(
                'SELECT COUNT(*) c FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND seq_in_index = 1',
                ['gp_source_link', 'account_id'],
            )->c > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
