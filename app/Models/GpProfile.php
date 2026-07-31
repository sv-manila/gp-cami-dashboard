<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of a gp-cami golden_profile master person record
 * (gp_identity_profile). This dashboard never writes to gp-cami.
 */
class GpProfile extends Model
{
    protected $connection = 'golden_profile';
    protected $table = 'gp_identity_profile';
    protected $primaryKey = 'identity_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    // JSON rollup columns decoded automatically.
    protected $casts = [
        'identifiers'    => 'array',
        'addresses'      => 'array',
        'licenses'       => 'array',
        'credentials'    => 'array',
        'exclusions'     => 'array',
        'accounts'       => 'array',
        'aliases'        => 'array',
        'source_records' => 'array',
        'resolutions'    => 'array',
    ];

    /** Block all writes — dashboard is strictly read-only against gp-cami. */
    public function save(array $options = [])
    {
        throw new \RuntimeException('gp-cami dashboard is read-only.');
    }

    /**
     * Exact first+last name search, newest first.
     *
     * Plain `=` on purpose — the name columns are utf8mb4_unicode_ci, so the
     * comparison is already case-insensitive. Wrapping them in LOWER() made the
     * predicate non-sargable and forced a full scan of the 13M-row table
     * (~128s per search); plain equality uses idx_name_dob (last_name,
     * first_name, date_of_birth) and returns in ~0.03s.
     */
    public function scopeByName($query, ?string $first, ?string $last)
    {
        $cols = config('gpcami.columns');
        if ($first !== null && $first !== '') {
            $query->where($cols['first_name'], '=', trim($first));
        }
        if ($last !== null && $last !== '') {
            $query->where($cols['last_name'], '=', trim($last));
        }
        return $query->orderByDesc('last_updated');
    }
}
