<?php

namespace App\Http\Controllers;

use App\Models\GpProfile;
use App\Models\QualityFlag;
use App\Models\StatSnapshot;
use Illuminate\Http\Request;

/**
 * Data-quality view: how source records are distributed across identities, and
 * the identities that distribution says are wrong.
 *
 * A healthy hub is almost entirely one-record identities. Mass in the long tail
 * is over-merge — identity 3 ("John Smith") carries 12,452 source records and a
 * 69MB credentials blob, which is not a person, it is a name collision that the
 * name+dob tier swallowed. The flags here come from `gpdash:snapshot`, which
 * also re-checks each candidate for members that disagree on a high-precision
 * key: a shared key is why they merged, a conflicting one is why they should
 * not have.
 */
class QualityController extends Controller
{
    /** Buckets in display order — the snapshot writes them unordered. */
    private const BUCKETS = ['1', '2-5', '6-20', '21-100', '101-1000', '1000+'];

    public function index(Request $request)
    {
        $flag = $request->query('flag');

        $latest = StatSnapshot::query()->where('metric', StatSnapshot::RECORD_BUCKET)->max('captured_on');

        $histogram = [];
        if ($latest) {
            $values = StatSnapshot::query()
                ->where('metric', StatSnapshot::RECORD_BUCKET)
                ->where('captured_on', $latest)
                ->pluck('value', 'label');

            foreach (self::BUCKETS as $bucket) {
                $histogram[$bucket] = (int) ($values[$bucket] ?? 0);
            }
        }

        $flags = QualityFlag::query()
            ->when($flag, fn ($q) => $q->where('flag', $flag))
            ->orderByDesc('record_count')
            ->orderBy('flag')
            ->limit(200)
            ->get();

        return view('quality', [
            'histogram'  => $histogram,
            'total'      => array_sum($histogram),
            'capturedOn' => $latest,
            'flags'      => $flags,
            'counts'     => QualityFlag::query()->selectRaw('flag, COUNT(*) n')->groupBy('flag')->pluck('n', 'flag'),
            'activeFlag' => $flag,
            'profiles'   => $this->profilesFor($flags->pluck('identity_id')->all()),
        ]);
    }

    /**
     * @param  array<int,int>  $ids
     * @return \Illuminate\Support\Collection<int,GpProfile>
     */
    private function profilesFor(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return collect();
        }

        return GpProfile::query()->forList()->whereIn('identity_id', $ids)->get()->keyBy('identity_id');
    }
}
