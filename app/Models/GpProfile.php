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

    /** Exact first+last name search (case-insensitive), newest first. */
    public function scopeByName($query, ?string $first, ?string $last)
    {
        $cols = config('gpcami.columns');
        if ($first !== null && $first !== '') {
            $query->whereRaw("LOWER({$cols['first_name']}) = ?", [mb_strtolower(trim($first))]);
        }
        if ($last !== null && $last !== '') {
            $query->whereRaw("LOWER({$cols['last_name']}) = ?", [mb_strtolower(trim($last))]);
        }
        return $query->orderByDesc('last_updated');
    }
}
