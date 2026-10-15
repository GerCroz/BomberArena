<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameRule extends Model
{
    protected $fillable = [
        'version',
        'is_active',
        'settings',
        'created_by_user_id',
        'created_by_name',
        'change_summary',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Default game rules matrix.
     */
    public static function defaultSettings(): array
    {
        return [
            // A. Configuración de partidas
            'round_duration_seconds' => 180,
            'min_players' => 2,
            'max_players' => 4,
            'countdown_seconds' => 3,
            'end_on_last_survivor' => true,
            'draw_on_simultaneous_elimination' => true,
            'timeout_result' => 'draw',
            'allow_rematch' => true,

            // B. Bombas y explosiones
            'bomb_fuse_ms' => 2000,
            'bomb_initial_range' => 2,
            'bomb_initial_simultaneous' => 1,
            'allow_chain_reaction' => true,
            'explosion_visual_duration_ms' => 500,
            'self_damage' => true,
            'friendly_fire' => true,

            // C. Jugadores
            'player_initial_speed' => 1,
            'player_collisions' => false,
            'reconnection_enabled' => true,
            'reconnect_grace_seconds' => 15,
            'eliminate_on_disconnect' => true,
            'dead_players_block_movement' => false,

            // D. Tablero
            'board_cols' => 13,
            'board_rows' => 11,
            'block_density_percent' => 65,
            'powerups_enabled' => true,
            'powerup_drop_chance_percent' => 25,
            'random_map_generation' => true,
            'movement_defaults_version' => 2,
            'movement_speed' => 4.0,
            'simulation_hz' => 20,
            'publish_hz' => 10,
            'corner_assist' => 0.25,
            'local_prediction' => true,
            'remote_interpolation' => true,
            'visual_correction_limit' => 0.75,
        ];
    }
}
