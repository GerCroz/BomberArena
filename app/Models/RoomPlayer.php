<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomPlayer extends Model
{
    protected $fillable = [
        'room_id',
        'player_id',
        'nickname',
        'color',
        'slot_index',
        'is_host',
        'is_ready',
        'is_alive',
        'score',
        'stats',
        'last_seen_at',
    ];

    protected $casts = [
        'is_host' => 'boolean',
        'is_ready' => 'boolean',
        'is_alive' => 'boolean',
        'score' => 'integer',
        'slot_index' => 'integer',
        'stats' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
