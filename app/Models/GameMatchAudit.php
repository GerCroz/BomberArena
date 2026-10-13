<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameMatchAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'room_code',
        'from_state',
        'to_state',
        'reason',
        'details',
        'alive_count',
        'remaining_seconds',
        'occurred_at',
    ];

    protected $casts = [
        'details' => 'array',
        'alive_count' => 'integer',
        'remaining_seconds' => 'integer',
        'occurred_at' => 'datetime',
    ];
}
