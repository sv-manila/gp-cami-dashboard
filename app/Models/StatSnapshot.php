<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recorded value for one stat on one day, on the dashboard's own database.
 * Written by `gpdash:snapshot`, read by the trend sparklines and the quality
 * histogram. Never touches the hub.
 */
class StatSnapshot extends Model
{
    protected $table = 'gp_stat_snapshots';
    public $timestamps = false;

    protected $fillable = ['captured_on', 'metric', 'label', 'value', 'approx', 'captured_at'];

    protected $casts = [
        'captured_on' => 'date',
        'value'       => 'integer',
        'approx'      => 'boolean',
        'captured_at' => 'datetime',
    ];

    /** Metric names, kept in one place so the command and the views agree. */
    public const TABLE_COUNT = 'table_count';
    public const RECORD_BUCKET = 'record_count_bucket';
    public const MATCH_STATE = 'match_state';
    public const LINK_QUALITY = 'link_quality';

    /**
     * The most recent recorded table counts, for the Overview page's at-a-glance
     * strip: ['as_of' => 'YYYY-MM-DD', 'counts' => [label => ['value'=>int,'approx'=>bool]]].
     *
     * Reads this app's own snapshot table, never the hub -- the Overview page is
     * documentation and must not cost a 13M-row scan to open. Returns an empty
     * array before the first `gpdash:snapshot` run, which the view reads as
     * "there is nothing to show yet, hide the strip".
     */
    public static function latestCounts(): array
    {
        $asOf = static::where('metric', self::TABLE_COUNT)->max('captured_on');
        if (! $asOf) {
            return [];
        }

        $rows = static::query()
            ->where('metric', self::TABLE_COUNT)
            ->where('captured_on', $asOf)
            ->get(['label', 'value', 'approx']);

        $counts = [];
        foreach ($rows as $r) {
            $counts[$r->label] = ['value' => (int) $r->value, 'approx' => (bool) $r->approx];
        }

        return ['as_of' => substr((string) $asOf, 0, 10), 'counts' => $counts];
    }

    /**
     * Series for one metric: label => [ 'YYYY-MM-DD' => value ], oldest first.
     *
     * @return array<string, array<string,int>>
     */
    public static function series(string $metric, int $days = 30): array
    {
        $rows = static::query()
            ->where('metric', $metric)
            ->where('captured_on', '>=', now()->subDays($days)->toDateString())
            ->orderBy('captured_on')
            ->get(['captured_on', 'label', 'value']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->label][$r->captured_on->toDateString()] = (int) $r->value;
        }

        return $out;
    }
}
