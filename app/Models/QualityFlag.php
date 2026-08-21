<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An identity the snapshot flagged as suspicious (over-merged, conflicting keys). */
class QualityFlag extends Model
{
    protected $table = 'gp_quality_flags';
    public $timestamps = false;

    protected $fillable = [
        'identity_id', 'flag', 'first_name', 'last_name', 'record_count', 'detail', 'captured_at',
    ];

    protected $casts = [
        'identity_id'  => 'integer',
        'record_count' => 'integer',
        'captured_at'  => 'datetime',
    ];

    public const OVER_MERGE = 'over_merge';
    public const DOB_CONFLICT = 'dob_conflict';
    public const NPI_CONFLICT = 'npi_conflict';
}
