<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Derived answer to "why are these source records one person?", precomputed by
 * `gpdash:merge-basis` because the hub does not record it (match_key is stamped
 * at first link and never rewritten by a later dedup merge).
 */
class MergeBasis extends Model
{
    protected $table = 'gp_identity_merge_basis';
    protected $primaryKey = 'identity_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'identity_id', 'member_count', 'basis', 'conflicts', 'truncated', 'computed_at',
    ];

    protected $casts = [
        'identity_id'  => 'integer',
        'member_count' => 'integer',
        'basis'        => 'array',
        'conflicts'    => 'array',
        'truncated'    => 'boolean',
        'computed_at'  => 'datetime',
    ];
}
