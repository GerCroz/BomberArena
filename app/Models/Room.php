<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    // Authoritative Match State Machine constants
    public const STATE_WAITING = 'waiting';

    public const STATE_READY = 'ready';

    public const STATE_COUNTDOWN = 'countdown';

    public const STATE_PLAYING = 'playing';

    public const STATE_FINISHED = 'finished';

    public const STATE_CANCELLED = 'cancelled';

    // Structured Match Termination Reasons
    public const REASON_LAST_PLAYER_ALIVE = 'LAST_PLAYER_ALIVE';

    public const REASON_ALL_PLAYERS_ELIMINATED = 'ALL_PLAYERS_ELIMINATED';

    public const REASON_TIME_EXPIRED = 'TIME_EXPIRED';

    public const REASON_ADMIN_CANCELLED = 'ADMIN_CANCELLED';

    public const REASON_DISCONNECT_FORFEIT = 'DISCONNECT_FORFEIT';

    protected $fillable = [
        'code',
        'host_player_id',
        'status',
        'max_players',
        'round_duration_seconds',
        'map_seed',
        'game_state',
        'winner_player_id',
        'finish_reason',
        'rules_snapshot',
        'rules_version',
        'total_bombs_detonated',
        'total_blocks_destroyed',
        'total_eliminations',
        'started_at',
        'finished_at',
        'last_activity_at',
    ];

    protected $casts = [
        'game_state' => 'array',
        'rules_snapshot' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'max_players' => 'integer',
        'round_duration_seconds' => 'integer',
        'map_seed' => 'integer',
        'rules_version' => 'integer',
        'total_bombs_detonated' => 'integer',
        'total_blocks_destroyed' => 'integer',
        'total_eliminations' => 'integer',
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

    public function audits(): HasMany
    {
        return $this->hasMany(GameMatchAudit::class, 'room_code', 'code');
    }
}
