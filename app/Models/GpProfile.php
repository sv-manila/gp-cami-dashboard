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
     * The narrow column set every list view uses.
     *
     * A single row here can carry >100MB of JSON rollups, so `SELECT *` over a
     * page of results is an out-of-memory crash rather than a slow query. Only
     * the one-identity profile view loads the rollups.
     *
     * Search predicates themselves live in App\Services\ProfileSearch.
     */
    public function scopeForList($query)
    {
        return $query->select(config('gpcami.list_columns'));
    }
}
