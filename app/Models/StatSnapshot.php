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
