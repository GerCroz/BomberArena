<?php

namespace App\Services;

use App\Models\GameRule;
use Illuminate\Support\Facades\Cache;

class GameRulesService
{
    public const CACHE_KEY = 'bomber_active_game_rules';

    /**
     * Get the active game rules as an array.
     */
    public function getActiveRules(): array
    {
        $rules = Cache::remember(self::CACHE_KEY, 3600, function () {
            $record = GameRule::where('is_active', true)->orderByDesc('version')->first();
            if ($record) {
                return array_merge(GameRule::defaultSettings(), $record->settings, [
                    '__version' => $record->version,
                    'movement_defaults_version' => $record->settings['movement_defaults_version'] ?? 1,
                ]);
            }

            // Seed default if database is empty
            $defaults = GameRule::defaultSettings();
            $newRecord = GameRule::create([
                'version' => 1,
                'is_active' => true,
                'settings' => $defaults,
                'created_by_name' => 'Sistema (Default)',
                'change_summary' => 'Configuración inicial por defecto',
            ]);

            return array_merge($defaults, ['__version' => $newRecord->version]);
        });
        if (($rules['movement_defaults_version'] ?? 1) < 2) {
            $rules['player_collisions'] = false;
            $rules['timeout_result'] = 'draw';
        }

        return array_merge(GameRule::defaultSettings(), $rules, ['movement_defaults_version' => 2]);
    }

    /**
     * Get the active GameRule model record.
     */
    public function getActiveRecord(): GameRule
    {
        $record = GameRule::where('is_active', true)->orderByDesc('version')->first();
        if (! $record) {
            $this->getActiveRules(); // will trigger seeding
            $record = GameRule::where('is_active', true)->orderByDesc('version')->first();
        }

        return $record;
    }

    /**
     * Save a new version of game rules.
     */
    public function saveNewRules(array $settings, ?int $userId = null, ?string $userName = null, ?string $summary = null): GameRule
    {
        $validated = $this->validateSettings($settings);

        $latestVersion = GameRule::max('version') ?? 0;
        $newVersion = $latestVersion + 1;

        // Deactivate previous active rules
        GameRule::where('is_active', true)->update(['is_active' => false]);

        $record = GameRule::create([
            'version' => $newVersion,
            'is_active' => true,
            'settings' => $validated,
            'created_by_user_id' => $userId,
            'created_by_name' => $userName ?: 'Administrador',
            'change_summary' => $summary ?: "Actualización a versión {$newVersion}",
        ]);

        Cache::forget(self::CACHE_KEY);

        return $record;
    }

    /**
     * Validate rules according to administrative constraints.
     */
    public function validateSettings(array $input): array
    {
        $defaults = GameRule::defaultSettings();
        $merged = array_merge($defaults, $input);

        // Validation limits
        $merged['round_duration_seconds'] = max(30, min(1200, (int) ($merged['round_duration_seconds'] ?? 180)));
        $merged['min_players'] = max(2, min(4, (int) ($merged['min_players'] ?? 2)));
        $merged['max_players'] = max($merged['min_players'], min(4, (int) ($merged['max_players'] ?? 4)));
        $merged['countdown_seconds'] = max(0, min(10, (int) ($merged['countdown_seconds'] ?? 3)));
        $merged['end_on_last_survivor'] = (bool) ($merged['end_on_last_survivor'] ?? true);
        $merged['draw_on_simultaneous_elimination'] = (bool) ($merged['draw_on_simultaneous_elimination'] ?? true);
        $merged['timeout_result'] = in_array($merged['timeout_result'] ?? '', ['draw', 'most_bombs', 'most_kills'], true) ? $merged['timeout_result'] : 'draw';
        $merged['allow_rematch'] = (bool) ($merged['allow_rematch'] ?? true);

        $merged['bomb_fuse_ms'] = max(500, min(10000, (int) ($merged['bomb_fuse_ms'] ?? 2000)));
        $merged['bomb_initial_range'] = max(1, min(10, (int) ($merged['bomb_initial_range'] ?? 2)));
        $merged['bomb_initial_simultaneous'] = max(1, min(5, (int) ($merged['bomb_initial_simultaneous'] ?? 1)));
        $merged['allow_chain_reaction'] = (bool) ($merged['allow_chain_reaction'] ?? true);
        $merged['explosion_visual_duration_ms'] = max(200, min(2000, (int) ($merged['explosion_visual_duration_ms'] ?? 500)));
        $merged['self_damage'] = (bool) ($merged['self_damage'] ?? true);
        $merged['friendly_fire'] = (bool) ($merged['friendly_fire'] ?? true);

        $merged['player_initial_speed'] = max(1, min(3, (int) ($merged['player_initial_speed'] ?? 1)));
        $merged['player_collisions'] = (bool) ($merged['player_collisions'] ?? false);
        $merged['reconnection_enabled'] = (bool) ($merged['reconnection_enabled'] ?? true);
        $merged['reconnect_grace_seconds'] = max(0, min(120, (int) ($merged['reconnect_grace_seconds'] ?? 15)));
        $merged['eliminate_on_disconnect'] = (bool) ($merged['eliminate_on_disconnect'] ?? true);
        $merged['dead_players_block_movement'] = false;

        $merged['board_cols'] = 13; // Fixed standard board width
        $merged['board_rows'] = 11; // Fixed standard board height
        $merged['block_density_percent'] = max(10, min(90, (int) ($merged['block_density_percent'] ?? 65)));
        $merged['powerups_enabled'] = (bool) ($merged['powerups_enabled'] ?? true);
        $merged['powerup_drop_chance_percent'] = max(0, min(100, (int) ($merged['powerup_drop_chance_percent'] ?? 25)));
        $merged['random_map_generation'] = (bool) ($merged['random_map_generation'] ?? true);
        $merged['movement_defaults_version'] = 2;
        $merged['movement_speed'] = max(2.0, min(6.0, (float) ($merged['movement_speed'] ?? 4)));
        $merged['simulation_hz'] = in_array((int) ($merged['simulation_hz'] ?? 20), [20, 25, 30], true) ? (int) $merged['simulation_hz'] : 20;
        $merged['publish_hz'] = max(5, min($merged['simulation_hz'], (int) ($merged['publish_hz'] ?? 10)));
        $merged['corner_assist'] = max(0, min(0.25, (float) ($merged['corner_assist'] ?? 0.25)));
        $merged['local_prediction'] = (bool) ($merged['local_prediction'] ?? true);
        $merged['remote_interpolation'] = (bool) ($merged['remote_interpolation'] ?? true);
        $merged['visual_correction_limit'] = max(0.25, min(1, (float) ($merged['visual_correction_limit'] ?? 0.75)));

        return $merged;
    }
}
