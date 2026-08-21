<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Precomputed per-account link/identity counts. Written by `gpdash:snapshot`. */
class AccountRollup extends Model
{
    protected $table = 'gp_account_rollups';
    protected $primaryKey = 'account_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['account_id', 'account_name', 'link_count', 'identity_count', 'captured_at'];

    protected $casts = [
        'account_id'     => 'integer',
        'link_count'     => 'integer',
        'identity_count' => 'integer',
        'captured_at'    => 'datetime',
    ];
}
