<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $fillable = [
        'code',
        'host_player_id',
        'status',
        'max_players',
        'round_duration_seconds',
        'map_seed',
        'game_state',
        'winner_player_id',
        'started_at',
        'finished_at',
        'last_activity_at',
    ];

    protected $casts = [
        'game_state' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'max_players' => 'integer',
        'round_duration_seconds' => 'integer',
        'map_seed' => 'integer',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(RoomPlayer::class);
    }

    public function isFull(): bool
    {
        return $this->players()->count() >= $this->max_players;
    }

    public function isHost(string $playerId): bool
    {
        return $this->host_player_id === $playerId;
    }
}
