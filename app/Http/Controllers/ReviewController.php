<?php

namespace App\Http\Controllers;

use App\Models\GpProfile;
use App\Models\MergeBasis;
use App\Models\StatSnapshot;
use App\Services\MergeBasisDeriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Steward review queue.
 *
 * The per-profile view already worked out whether an identity's links needed a
 * human — but only after you had found and opened that identity, which is the
 * wrong way round. This is the same signal aggregated: every link the resolver
 * was not confident about, weakest first, with a side-by-side compare for the
 * merge decision itself.
 */
class ReviewController extends Controller
{
    /** States the resolver did not settle on its own. */
    private const OPEN_STATES = ['review', 'no_match'];

    private const PER_PAGE = 40;

    public function index(Request $request)
    {
        $db = DB::connection(config('gpcami.connection'));
        $state = $request->query('state', 'open');
        $states = $state === 'pinned' ? ['pinned'] : self::OPEN_STATES;

        $summary = $this->summary();
        $links = [];
        $error = null;

        try {
            // idx_match_state makes this a range read over the flagged links
            // only — the 13.4M auto_match rows are never touched.
            $links = $db->select(
                'SELECT /*+ MAX_EXECUTION_TIME(15000) */
                        l.link_id, l.identity_id, l.source_table, l.source_id, l.account_id,
                        l.match_method, l.match_key, l.match_score, l.match_state, l.is_pinned, l.linked_at
                   FROM gp_source_link l
                  WHERE l.match_state IN (' . implode(',', array_fill(0, count($states), '?')) . ')
                  ORDER BY l.match_score IS NULL, l.match_score ASC, l.link_id
                  LIMIT ' . self::PER_PAGE,
                $states,
            );
        } catch (\Throwable $e) {
            Log::error('review queue query failed', ['exception' => $e->getMessage()]);
            $error = 'Could not read the link queue from the hub.';
        }

        return view('review', [
            'links'    => $links,
            'names'    => $this->namesFor(array_map(fn ($l) => (int) $l->identity_id, $links)),
            'summary'  => $summary,
            'state'    => $state,
            'error'    => $error,
            'perPage'  => self::PER_PAGE,
        ]);
    }

    /**
     * Queue depth by state and score band, from last night's snapshot.
     *
     * Counting these live means a GROUP BY over every link in the hub, which is
     * a batch job's work, not a page's — `gpdash:snapshot --only=links` records
     * it nightly.
     *
     * @return array{states:array<string,int>,bands:array<string,int>,captured_on:?string}
     */
    private function summary(): array
    {
        $latest = StatSnapshot::query()
            ->whereIn('metric', [StatSnapshot::MATCH_STATE, StatSnapshot::LINK_QUALITY])
            ->max('captured_on');

        if (! $latest) {
            return ['states' => [], 'bands' => [], 'captured_on' => null];
        }

        $rows = StatSnapshot::query()
            ->where('captured_on', $latest)
            ->whereIn('metric', [StatSnapshot::MATCH_STATE, StatSnapshot::LINK_QUALITY])
            ->get();

        return [
            'states' => $rows->where('metric', StatSnapshot::MATCH_STATE)->pluck('value', 'label')->all(),
            'bands'  => $rows->where('metric', StatSnapshot::LINK_QUALITY)->pluck('value', 'label')->all(),
            'captured_on' => $latest,
        ];
    }

    /**
     * Side-by-side of two identities, for deciding whether they are one person.
     *
     * Shows the canonical fields next to each other with the differing ones
     * called out, plus each side's merge basis — the same evidence a steward
     * would otherwise have to open two tabs to compare.
     */
    public function compare(Request $request, MergeBasisDeriver $deriver)
    {
        $ids = array_values(array_filter([
            (int) $request->query('a'),
            (int) $request->query('b'),
        ]));

        $profiles = $ids
            ? GpProfile::query()->forList()->whereIn('identity_id', $ids)->get()->keyBy('identity_id')
            : collect();

        $basis = [];
        foreach ($ids as $id) {
            if (! $profiles->has($id)) {
                continue;
            }
            $basis[$id] = ($stored = MergeBasis::find($id))
                ? ['basis' => $stored->basis ?? [], 'conflicts' => $stored->conflicts ?? [], 'source' => 'precomputed']
                : $this->deriveSafely($deriver, $id);
        }

        return view('compare', [
            'ids'      => $ids,
            'profiles' => $profiles,
            'basis'    => $basis,
            'fields'   => $this->compareFields(),
        ]);
    }

    /** @return array{basis:array,conflicts:array,source:string} */
    private function deriveSafely(MergeBasisDeriver $deriver, int $id): array
    {
        try {
            $d = $deriver->derive($id);

            return ['basis' => $d['basis'], 'conflicts' => $d['conflicts'], 'source' => 'derived now'];
        } catch (\Throwable $e) {
            return ['basis' => [], 'conflicts' => [], 'source' => 'unavailable'];
        }
    }

    /**
     * Fields worth lining up, in the order a steward reads them: identity keys
     * first (a disagreement here is decisive), then the softer attributes.
     *
     * @return array<string,string> column => label
     */
    private function compareFields(): array
    {
        return [
            'npi' => 'NPI',
            'dea_number' => 'DEA',
            'ssn_last_four' => 'SSN last four',
            'date_of_birth' => 'Date of birth',
            'first_name' => 'First name',
            'middle_name' => 'Middle name',
            'last_name' => 'Last name',
            'suffix' => 'Suffix',
            'city' => 'City',
            'state' => 'State',
            'license_count' => 'Licenses',
            'credential_count' => 'Credentials',
            'exclusion_count' => 'Exclusions',
            'has_active_exclusion' => 'Active exclusion',
            'record_count' => 'Source records',
            'account_count' => 'Accounts',
            'confidence' => 'Confidence',
            'last_updated' => 'Last updated',
        ];
    }

    /**
     * Names for the queue rows. One indexed read for the whole page rather than
     * a join the queue query would otherwise pay for on every scan.
     *
     * @param  array<int,int>  $ids
     * @return \Illuminate\Support\Collection<int,GpProfile>
     */
    private function namesFor(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return collect();
        }

        return GpProfile::query()->forList()->whereIn('identity_id', $ids)->get()->keyBy('identity_id');
    }
}
