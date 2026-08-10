<?php

namespace App\Http\Controllers;

use App\Models\StatSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Is the hub current?" — the one question the stats board could not answer.
 *
 * A count of 13.4M profiles looks identical whether the sync ran an hour ago or
 * stalled a fortnight back. This reads the watermark the sync actually advances,
 * compares it against the newest row in the source, and shows the staging
 * checkpoints the backfill left behind.
 */
class PipelineController extends Controller
{
    /** Watermark rows whose source_table looks like `stg:N` are backfill checkpoints. */
    private const CHECKPOINT_PREFIX = 'stg:';

    /** Freshness thresholds in hours: current / behind / stale. */
    private const BEHIND_HOURS = 24;
    private const STALE_HOURS = 72;

    public function index()
    {
        $db = DB::connection(config('gpcami.connection'));

        $watermarks = $checkpoints = [];
        $error = null;

        try {
            foreach ($db->select('SELECT system_id, source_table, high_water, updated_at FROM gp_watermark ORDER BY source_table') as $row) {
                str_starts_with($row->source_table, self::CHECKPOINT_PREFIX)
                    ? $checkpoints[] = $row
                    : $watermarks[] = $row;
            }
        } catch (\Throwable $e) {
            Log::error('watermark read failed', ['exception' => $e->getMessage()]);
            $error = 'Could not read gp_watermark from the hub.';
        }

        $sourceHead = $this->sourceHead();
        $sync = $this->syncStatus($watermarks, $sourceHead);

        return view('pipeline', [
            'watermarks'  => $watermarks,
            'checkpoints' => $checkpoints,
            'sourceHead'  => $sourceHead,
            'sync'        => $sync,
            'backlog'     => $this->stagingBacklog(),
            'lastSnapshot' => StatSnapshot::query()->max('captured_on'),
            'error'       => $error,
        ]);
    }

    /**
     * Newest source row the sync could have picked up.
     *
     * employees.date_modified is indexed, so MAX() is an index read, not a scan.
     * Cached for a minute — this page is a refresh target and the answer does
     * not move faster than that.
     */
    private function sourceHead(): ?Carbon
    {
        // Cached as a plain string, not a Carbon: the cache store round-trips
        // through serialize(), and the date object came back as an
        // __PHP_Incomplete_Class on the next request. Parsing after the read
        // costs nothing and keeps only scalars in the cache.
        $raw = Cache::remember('gpcami.source_head', 60, function () {
            try {
                $r = DB::connection(config('gpcami.source_connection'))
                    ->selectOne('SELECT MAX(date_modified) m FROM employees');

                return $r?->m ? (string) $r->m : '';
            } catch (\Throwable $e) {
                Log::error('source head read failed', ['exception' => $e->getMessage()]);

                return '';
            }
        });

        return $raw ? Carbon::parse($raw) : null;
    }

    /**
     * Lag between what the hub has consumed and what the source holds.
     *
     * @param  array<int,object>  $watermarks
     * @return array{mark:?Carbon,lag_hours:?float,level:string,label:string}
     */
    private function syncStatus(array $watermarks, ?Carbon $sourceHead): array
    {
        $employees = collect($watermarks)->firstWhere('source_table', config('gpcami.source_table', 'employees'));

        $mark = null;
        if ($employees?->high_water) {
            try {
                $mark = Carbon::parse($employees->high_water);
            } catch (\Throwable $e) {
                // A non-datetime high_water means this system syncs by id, not
                // by timestamp — there is no lag to compute.
            }
        }

        if (! $mark || ! $sourceHead) {
            return ['mark' => $mark, 'lag_hours' => null, 'level' => 'unknown', 'label' => 'Lag unknown'];
        }

        $lag = $mark->diffInMinutes($sourceHead, false) / 60;
        $lag = max(0, $lag);

        [$level, $label] = match (true) {
            $lag < self::BEHIND_HOURS => ['ok', 'Current'],
            $lag < self::STALE_HOURS  => ['warn', 'Behind'],
            default                   => ['crit', 'Stale'],
        };

        return ['mark' => $mark, 'lag_hours' => $lag, 'level' => $level, 'label' => $label];
    }

    /**
     * Where staged rows ended up: still unresolved, or folded into an identity.
     *
     * Every side is counted nightly by `gpdash:snapshot`, so this is arithmetic
     * rather than an anti-join over 13M rows.
     *
     *  - backlog  = staged rows with no link at all. Non-zero means resolve
     *               stopped short of what stage loaded.
     *  - collapse = links minus identities, i.e. how many source records dedup
     *               folded into an identity that already existed. This is the
     *               pipeline working, not a fault — but a sudden jump is the
     *               first sign of an over-merging tier.
     *
     * @return array{staged:?int,linked:?int,identities:?int,backlog:?int,collapse:?int,captured_on:?string}
     */
    private function stagingBacklog(): array
    {
        $latest = StatSnapshot::query()
            ->whereIn('metric', [StatSnapshot::TABLE_COUNT, StatSnapshot::MATCH_STATE])
            ->max('captured_on');

        $empty = ['staged' => null, 'linked' => null, 'identities' => null,
            'backlog' => null, 'collapse' => null, 'captured_on' => null];

        if (! $latest) {
            return $empty;
        }

        $rows = StatSnapshot::query()
            ->where('captured_on', $latest)
            ->whereIn('metric', [StatSnapshot::TABLE_COUNT, StatSnapshot::MATCH_STATE])
            ->get();

        $counts = $rows->where('metric', StatSnapshot::TABLE_COUNT)->pluck('value', 'label');
        $staged = isset($counts['Staged persons']) ? (int) $counts['Staged persons'] : null;
        $identities = isset($counts['Identity profiles']) ? (int) $counts['Identity profiles'] : null;

        // Every gp_source_link row carries a match_state, so the states sum to
        // the link total without counting that 13M-row table a second time.
        $stateRows = $rows->where('metric', StatSnapshot::MATCH_STATE);
        $linked = $stateRows->isNotEmpty() ? (int) $stateRows->sum('value') : null;

        return [
            'staged'     => $staged,
            'linked'     => $linked,
            'identities' => $identities,
            'backlog'    => ($staged !== null && $linked !== null) ? max(0, $staged - $linked) : null,
            'collapse'   => ($linked !== null && $identities !== null) ? max(0, $linked - $identities) : null,
            'captured_on' => $latest,
        ];
    }
}
