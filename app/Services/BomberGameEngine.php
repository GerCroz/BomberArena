<?php

namespace App\Services;

use Random\Engine\Mt19937;
use Random\Randomizer;

class BomberGameEngine
{
    public const WIDTH = 13;

    public const HEIGHT = 11;

    public const TICK_SECONDS = 0.05;

    // Tile Types
    public const TILE_EMPTY = 0;

    public const TILE_WALL = 1;       // Indestructible (outer wall or fixed pillar)

    public const TILE_BLOCK = 2;      // Destructible block

    // Power-up Types
    public const POWERUP_SPEED = 'speed';

    public const POWERUP_BOMB = 'bomb';

    public const POWERUP_FIRE = 'fire';

    // Player spawn positions (x, y)
    public const SPAWN_POSITIONS = [
        0 => ['x' => 1, 'y' => 1],
        1 => ['x' => 11, 'y' => 9],
        2 => ['x' => 11, 'y' => 1],
        3 => ['x' => 1, 'y' => 9],
    ];

    public const SAFE_ZONES = [
        // Top-left
        ['x' => 1, 'y' => 1], ['x' => 1, 'y' => 2], ['x' => 2, 'y' => 1],
        ['x' => 3, 'y' => 1], ['x' => 3, 'y' => 2], ['x' => 1, 'y' => 3], ['x' => 2, 'y' => 3],
        // Bottom-right
        ['x' => 11, 'y' => 9], ['x' => 11, 'y' => 8], ['x' => 10, 'y' => 9],
        ['x' => 9, 'y' => 9], ['x' => 9, 'y' => 8], ['x' => 11, 'y' => 7], ['x' => 10, 'y' => 7],
        // Top-right
        ['x' => 11, 'y' => 1], ['x' => 10, 'y' => 1], ['x' => 11, 'y' => 2],
        ['x' => 9, 'y' => 1], ['x' => 9, 'y' => 2], ['x' => 11, 'y' => 3], ['x' => 10, 'y' => 3],
        // Bottom-left
        ['x' => 1, 'y' => 9], ['x' => 2, 'y' => 9], ['x' => 1, 'y' => 8],
        ['x' => 3, 'y' => 9], ['x' => 3, 'y' => 8], ['x' => 1, 'y' => 7], ['x' => 2, 'y' => 7],
    ];

    /**
     * Generate an initial map and state for a room using an immutable rules snapshot.
     */
    public function createInitialState(array $playerRecords, ?int $seed = null, array $rules = []): array
    {
        $rules['player_collisions'] = true;
        $rules['dead_players_block_movement'] = false;
        $seed = $seed ?? mt_rand(10000, 99999);
        $random = new Randomizer(new Mt19937($seed));

        $blockDensity = (int) ($rules['block_density_percent'] ?? 65);
        $grid = [];
        $powerups = [];

        for ($y = 0; $y < self::HEIGHT; $y++) {
            $row = [];
            for ($x = 0; $x < self::WIDTH; $x++) {
                // Outer borders
                if ($x === 0 || $x === self::WIDTH - 1 || $y === 0 || $y === self::HEIGHT - 1) {
                    $row[] = self::TILE_WALL;

                    continue;
                }

                // Fixed interior pillars
                if ($x % 2 === 0 && $y % 2 === 0) {
                    $row[] = self::TILE_WALL;

                    continue;
                }

                // Safe zones around spawns
                if ($this->isSafeZone($x, $y)) {
                    $row[] = self::TILE_EMPTY;

                    continue;
                }

                // Destructible block distribution according to configured density
                if ($random->getInt(1, 100) <= $blockDensity) {
                    $row[] = self::TILE_BLOCK;
                } else {
                    $row[] = self::TILE_EMPTY;
                }
            }
            $grid[] = $row;
        }

        // Initialize players
        $players = [];
        $colors = ['neon-blue', 'neon-pink', 'neon-yellow', 'neon-green'];

        $initialSpeed = (int) ($rules['player_initial_speed'] ?? 1);
        $initialMaxBombs = (int) ($rules['bomb_initial_simultaneous'] ?? 1);
        $initialRange = (int) ($rules['bomb_initial_range'] ?? 2);

        foreach ($playerRecords as $index => $record) {
            $slot = $record['slot_index'] ?? $index;
            $spawn = self::SPAWN_POSITIONS[$slot] ?? ['x' => 1, 'y' => 1];

            $players[$record['player_id']] = [
                'id' => $record['player_id'],
                'nickname' => $record['nickname'] ?? ('Player '.($slot + 1)),
                'color' => $colors[$slot % 4],
                'slot' => $slot,
                'x' => $spawn['x'],
                'y' => $spawn['y'],
                'direction' => 'down',
                'alive' => true,
                'speed' => $initialSpeed,
                'max_bombs' => $initialMaxBombs,
                'bomb_range' => $initialRange,
                'active_bombs' => 0,
                'kills' => 0,
                'bombs_placed' => 0,
                'powerups_collected' => 0,
            ];
        }

        $duration = (int) ($rules['round_duration_seconds'] ?? 180);
        $startedAt = (float) now()->format('U.u');
        foreach ($players as &$player) {
            $player['last_seen_at'] = $startedAt;
        }
        unset($player);

        return [
            'round_id' => bin2hex(random_bytes(16)),
            'seed' => $seed,
            'version' => 1,
            'tick' => 0,
            'pending_moves' => [],
            'movement_intents' => [],
            'bomb_sequence' => 0,
            'input_sequences' => [],
            'movement_ready_at' => [],
            'diagnostics' => ['rejected_collisions' => 0, 'rejected_moves' => 0],
            'grid' => $grid,
            'players' => $players,
            'initial_player_count' => count($players),
            'bombs' => [], // id => [id, x, y, owner_id, range, planted_at, explodes_at]
            'explosions' => [],
            'powerups' => $powerups,
            'winner_id' => null,
            'is_game_over' => false,
            'finish_reason' => null,
            'started_at' => $startedAt,
            'duration_seconds' => $duration,
            'rules' => $rules,
            'stats' => [
                'total_bombs_detonated' => 0,
                'total_blocks_destroyed' => 0,
                'total_eliminations' => 0,
            ],
        ];
    }

    /**
     * Resolve a closed input tick against its starting occupancy. Contested
     * destinations reject every contender; occupied origins cannot be entered.
     *
     * @param  array<string, string>  $directions
     * @return array<string, array>
     */
    public function resolveMovementTick(array &$state, array $directions, float $time): array
    {
        ksort($directions);
        $origins = [];
        foreach ($state['players'] as $id => $player) {
            if ($player['alive']) {
                $origins[$player['x'].'_'.$player['y']] = $id;
            }
        }
        $targets = [];
        $destinations = [];
        foreach ($directions as $id => $direction) {
            $player = $state['players'][$id] ?? null;
            $delta = ['up' => [0, -1], 'down' => [0, 1], 'left' => [-1, 0], 'right' => [1, 0]][$direction] ?? null;
            if (! $player || ! $player['alive'] || ! $delta) {
                continue;
            }
            $key = ($player['x'] + $delta[0]).'_'.($player['y'] + $delta[1]);
            $destinations[$id] = $key;
            $targets[$key][] = $id;
        }
        $results = [];
        foreach ($directions as $id => $direction) {
            $key = $destinations[$id] ?? null;
            if ($key !== null && (isset($origins[$key]) || count($targets[$key]) > 1)) {
                $results[$id] = ['success' => false, 'error' => 'Conflicto de ocupacion'];
            } elseif ($time + 0.000001 < ($state['movement_ready_at'][$id] ?? 0)) {
                $results[$id] = ['success' => false, 'error' => 'Movimiento demasiado rapido'];
            } else {
                $results[$id] = $this->handleMove($state, $id, $direction);
                if ($results[$id]['success']) {
                    $speed = max(1, min(3, (int) $state['players'][$id]['speed']));
                    $previousDeadline = $state['movement_ready_at'][$id] ?? $time;
                    $baseTime = $previousDeadline >= $time - self::TICK_SECONDS - 0.000001 ? $previousDeadline : $time;
                    $state['movement_ready_at'][$id] = $baseTime + (125 - $speed * 25) / 1000;
                }
            }
            if (! $results[$id]['success']) {
                $state['diagnostics']['rejected_moves'] = ($state['diagnostics']['rejected_moves'] ?? 0) + 1;
                if (($key !== null && (isset($origins[$key]) || count($targets[$key]) > 1)) || str_starts_with($results[$id]['error'], 'Casilla')) {
                    $state['diagnostics']['rejected_collisions'] = ($state['diagnostics']['rejected_collisions'] ?? 0) + 1;
                }
            }
        }

        return $results;
    }

    /**
     * Advance only closed ticks. Inputs expire after an interruption instead
     * of replaying a backlog of movement at reconnect time.
     *
     * @return array<int, array{0: string, 1: array}>
     */
    public function advanceMovement(array &$state, float $time): array
    {
        $events = [];
        $closedTick = max(0, (int) floor(($time - $state['started_at']) / self::TICK_SECONDS));
        for ($tick = max(($state['tick'] ?? 0) + 1, $closedTick - 9); $tick <= $closedTick; $tick++) {
            $tickTime = $state['started_at'] + $tick * self::TICK_SECONDS;
            foreach ($state['movement_intents'] ?? [] as $id => $intent) {
                if ($tickTime >= $intent['started_at'] && $tickTime <= $intent['expires_at'] && $tickTime + 0.000001 >= ($state['movement_ready_at'][$id] ?? 0)) {
                    $state['pending_moves'][$tick][$id] ??= $intent['direction'];
                }
            }
        }
        $pending = $state['pending_moves'] ?? [];
        ksort($pending, SORT_NUMERIC);
        foreach ($pending as $tick => $commands) {
            if ($tick > $closedTick) {
                continue;
            }
            unset($state['pending_moves'][$tick]);
            $tickTime = $state['started_at'] + $tick * self::TICK_SECONDS;
            if ($time - $tickTime > 0.5 || ! empty($state['is_game_over'])) {
                continue;
            }
            foreach ($commands as $id => $direction) {
                if (isset($state['movement_intents'][$id]) && $tickTime + 0.000001 < ($state['movement_ready_at'][$id] ?? 0)) {
                    unset($commands[$id]);
                }
            }
            foreach ($this->checkAndDetonateExpiredBombs($state, $tickTime) as $explosion) {
                $events[] = ['bomb_exploded', $explosion];
            }
            $this->evaluateMatchOutcome($state, $tickTime);
            foreach ($this->resolveMovementTick($state, $commands, $tickTime) as $id => $result) {
                if ($result['success']) {
                    $player = $state['players'][$id];
                    $events[] = ['player_moved', [
                        'player_id' => $id, 'x' => $player['x'], 'y' => $player['y'],
                        'direction' => $player['direction'], 'stats' => $player,
                        'collected_powerup' => $result['collected_powerup'],
                        'collected_keys' => $result['collected_powerup'] ? [$player['x'].'_'.$player['y']] : [],
                        'tick' => (int) $tick,
                    ]];
                }
            }
        }
        $state['tick'] = max($state['tick'] ?? 0, $closedTick);
        foreach ($this->checkAndDetonateExpiredBombs($state, $time) as $explosion) {
            $events[] = ['bomb_exploded', $explosion];
        }
        $wasOver = ! empty($state['is_game_over']);
        $this->evaluateMatchOutcome($state, $time);
        if (empty($state['is_game_over']) && ($state['rules']['eliminate_on_disconnect'] ?? false)) {
            $grace = ($state['rules']['reconnection_enabled'] ?? true) ? max(0, (int) ($state['rules']['reconnect_grace_seconds'] ?? 15)) : 0;
            foreach ($state['players'] as $id => &$player) {
                if ($player['alive'] && isset($player['last_seen_at']) && $time - $player['last_seen_at'] > 1 + $grace) {
                    $player['alive'] = false;
                    $state['stats']['total_eliminations']++;
                    $state['version']++;
                    $events[] = ['player_disconnected', ['player_id' => $id, 'tick' => $closedTick]];
                }
            }
            unset($player);
            $this->evaluateMatchOutcome($state, $time);
        }
        if (! $wasOver && ! empty($state['is_game_over'])) {
            $state['version']++;
        }

        return $events;
    }

    protected function isSafeZone(int $x, int $y): bool
    {
        foreach (self::SAFE_ZONES as $zone) {
            if ($zone['x'] === $x && $zone['y'] === $y) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process player movement.
     */
    public function handleMove(array &$state, string $playerId, string $direction): array
    {
        if (! empty($state['is_game_over'])) {
            return ['success' => false, 'error' => 'Partida finalizada'];
        }
        if (! isset($state['players'][$playerId])) {
            return ['success' => false, 'error' => 'Jugador no encontrado'];
        }

        $player = &$state['players'][$playerId];
        if (! $player['alive']) {
            return ['success' => false, 'error' => 'Jugador eliminado'];
        }

        $dx = 0;
        $dy = 0;
        switch ($direction) {
            case 'up': $dy = -1;
                break;
            case 'down': $dy = 1;
                break;
            case 'left': $dx = -1;
                break;
            case 'right': $dx = 1;
                break;
            default: return ['success' => false, 'error' => 'Dirección inválida'];
        }

        $newX = $player['x'] + $dx;
        $newY = $player['y'] + $dy;

        // Check map boundaries
        if ($newX < 0 || $newX >= self::WIDTH || $newY < 0 || $newY >= self::HEIGHT) {
            return ['success' => false, 'error' => 'Fuera de límites', 'pos' => ['x' => $player['x'], 'y' => $player['y']]];
        }

        // Check solid tiles
        $tile = $state['grid'][$newY][$newX] ?? self::TILE_WALL;
        if ($tile === self::TILE_WALL || $tile === self::TILE_BLOCK) {
            return ['success' => false, 'error' => 'Casilla bloqueada por obstáculo', 'pos' => ['x' => $player['x'], 'y' => $player['y']]];
        }

        // Check bombs: cannot walk into a tile with an active bomb
        foreach ($state['bombs'] as $bomb) {
            if ($bomb['x'] === $newX && $bomb['y'] === $newY) {
                return ['success' => false, 'error' => 'Casilla bloqueada por bomba', 'pos' => ['x' => $player['x'], 'y' => $player['y']]];
            }
        }

        foreach ($state['players'] as $pid => $otherPlayer) {
            if ($pid !== $playerId && ! empty($otherPlayer['alive']) && $otherPlayer['x'] === $newX && $otherPlayer['y'] === $newY) {
                return ['success' => false, 'error' => 'Casilla ocupada por otro jugador', 'pos' => ['x' => $player['x'], 'y' => $player['y']]];
            }
        }

        // Apply movement
        $player['x'] = $newX;
        $player['y'] = $newY;
        $player['direction'] = $direction;
        $state['version']++;

        // Check power-up collection
        $puKey = "{$newX}_{$newY}";
        $collectedPowerUp = null;
        if (isset($state['powerups'][$puKey])) {
            $collectedPowerUp = $state['powerups'][$puKey];
            unset($state['powerups'][$puKey]);

            $player['powerups_collected']++;
            if ($collectedPowerUp === self::POWERUP_SPEED) {
                $player['speed'] = min(3, $player['speed'] + 1);
            } elseif ($collectedPowerUp === self::POWERUP_BOMB) {
                $player['max_bombs'] = min(5, $player['max_bombs'] + 1);
            } elseif ($collectedPowerUp === self::POWERUP_FIRE) {
                $player['bomb_range'] = min(6, $player['bomb_range'] + 1);
            }
        }

        return [
            'success' => true,
            'player' => $player,
            'collected_powerup' => $collectedPowerUp,
            'pos' => ['x' => $newX, 'y' => $newY],
        ];
    }

    /**
     * Plant a bomb by player with parameterized fuse duration.
     */
    public function handlePlaceBomb(array &$state, string $playerId): array
    {
        if (! empty($state['is_game_over'])) {
            return ['success' => false, 'error' => 'Partida finalizada'];
        }
        if (! isset($state['players'][$playerId])) {
            return ['success' => false, 'error' => 'Jugador no encontrado'];
        }

        $player = &$state['players'][$playerId];
        if (! $player['alive']) {
            return ['success' => false, 'error' => 'Jugador eliminado'];
        }

        // Check active bomb count
        $activeBombs = 0;
        foreach ($state['bombs'] as $b) {
            if ($b['owner_id'] === $playerId) {
                $activeBombs++;
            }
        }

        if ($activeBombs >= $player['max_bombs']) {
            return ['success' => false, 'error' => 'Límite de bombas alcanzado'];
        }

        $bx = $player['x'];
        $by = $player['y'];

        // Cannot plant if another bomb already exists here
        foreach ($state['bombs'] as $b) {
            if ($b['x'] === $bx && $b['y'] === $by) {
                return ['success' => false, 'error' => 'Ya hay una bomba en esta posición'];
            }
        }

        $fuseMs = (int) ($state['rules']['bomb_fuse_ms'] ?? 2000);
        $fuseSeconds = max(0.5, $fuseMs / 1000.0);

        $state['bomb_sequence'] = ($state['bomb_sequence'] ?? 0) + 1;
        $bombId = 'bomb_'.$state['bomb_sequence'];
        $plantedAt = (float) now()->format('U.u');
        $explodesAt = $plantedAt + $fuseSeconds;

        $bomb = [
            'id' => $bombId,
            'x' => $bx,
            'y' => $by,
            'owner_id' => $playerId,
            'range' => $player['bomb_range'],
            'planted_at' => $plantedAt,
            'explodes_at' => $explodesAt,
            'state' => 'active',
        ];

        $state['bombs'][$bombId] = $bomb;
        $player['active_bombs'] = $activeBombs + 1;
        $player['bombs_placed']++;
        $state['version']++;

        return [
            'success' => true,
            'bomb' => $bomb,
        ];
    }

    /**
     * Detonate a bomb and resolve cross-fire, block destruction, chain reactions, and eliminations.
     */
    public function detonateBomb(array &$state, string $bombId, bool $evaluateOutcome = true): array
    {
        if (! isset($state['bombs'][$bombId]) || ($state['bombs'][$bombId]['state'] ?? 'active') !== 'active') {
            return ['success' => false, 'error' => 'Bomba no encontrada o ya detonada'];
        }

        $rootBomb = $state['bombs'][$bombId];
        $rootBomb['state'] = 'detonated';
        unset($state['bombs'][$bombId]);

        $bombsToDetonate = [$rootBomb];
        $detonatedBombIds = [$rootBomb['id']];
        $allExplosionTiles = [];
        $damageOwners = [];
        $destroyedBlocks = [];
        $spawnedPowerUps = [];
        $eliminatedPlayers = [];

        $rules = $state['rules'] ?? [];
        $allowChain = $rules['allow_chain_reaction'] ?? true;
        $dropChance = (int) ($rules['powerup_drop_chance_percent'] ?? 25);
        $powerupsEnabled = $rules['powerups_enabled'] ?? true;
        $selfDamage = $rules['self_damage'] ?? true;
        $friendlyFire = $rules['friendly_fire'] ?? true;

        // Breadth-first explosion resolution for chain reactions
        while (! empty($bombsToDetonate)) {
            $currentBomb = array_shift($bombsToDetonate);
            $cx = $currentBomb['x'];
            $cy = $currentBomb['y'];
            $range = $currentBomb['range'];

            // Center tile
            $allExplosionTiles["{$cx}_{$cy}"] = ['x' => $cx, 'y' => $cy];
            $damageOwners["{$cx}_{$cy}"][] = $currentBomb['owner_id'];

            // 4 directions: [dx, dy]
            $directions = [
                ['dx' => 0, 'dy' => -1], // up
                ['dx' => 0, 'dy' => 1],  // down
                ['dx' => -1, 'dy' => 0], // left
                ['dx' => 1, 'dy' => 0],  // right
            ];

            foreach ($directions as $dir) {
                for ($step = 1; $step <= $range; $step++) {
                    $tx = $cx + ($dir['dx'] * $step);
                    $ty = $cy + ($dir['dy'] * $step);

                    // Out of bounds
                    if ($tx < 0 || $tx >= self::WIDTH || $ty < 0 || $ty >= self::HEIGHT) {
                        break;
                    }

                    // Check fixed wall -> STOPS immediately
                    if ($state['grid'][$ty][$tx] === self::TILE_WALL) {
                        break;
                    }

                    // Check destructible block -> DESTROY, STOP explosion here
                    if ($state['grid'][$ty][$tx] === self::TILE_BLOCK) {
                        $state['grid'][$ty][$tx] = self::TILE_EMPTY;
                        $destroyedBlocks[] = ['x' => $tx, 'y' => $ty];
                        $allExplosionTiles["{$tx}_{$ty}"] = ['x' => $tx, 'y' => $ty];
                        $damageOwners["{$tx}_{$ty}"][] = $currentBomb['owner_id'];

                        // Chance to spawn power-up
                        $random = new Randomizer(new Mt19937((int) hexdec(substr(hash('sha256', ($state['seed'] ?? 0).'|'.$currentBomb['id'].'|'.$tx.'|'.$ty), 0, 8))));
                        if ($powerupsEnabled && $random->getInt(1, 100) <= $dropChance) {
                            $types = [self::POWERUP_SPEED, self::POWERUP_BOMB, self::POWERUP_FIRE];
                            $chosen = $types[$random->getInt(0, 2)];
                            $state['powerups']["{$tx}_{$ty}"] = $chosen;
                            $spawnedPowerUps[] = ['x' => $tx, 'y' => $ty, 'type' => $chosen];
                        }
                        break; // stops propagation in this direction
                    }

                    // Empty tile: fire continues
                    $allExplosionTiles["{$tx}_{$ty}"] = ['x' => $tx, 'y' => $ty];
                    $damageOwners["{$tx}_{$ty}"][] = $currentBomb['owner_id'];

                    // Check chain reactions with other active bombs
                    if ($allowChain) {
                        foreach ($state['bombs'] as $otherId => $otherBomb) {
                            if ($otherBomb['x'] === $tx && $otherBomb['y'] === $ty) {
                                if (($otherBomb['state'] ?? 'active') === 'active') {
                                    $otherBomb['state'] = 'detonated';
                                    unset($state['bombs'][$otherId]);
                                    $bombsToDetonate[] = $otherBomb;
                                    $detonatedBombIds[] = $otherId;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Check player eliminations against all explosion tiles
        foreach ($state['players'] as $pid => &$player) {
            if (! $player['alive']) {
                continue;
            }

            $posKey = "{$player['x']}_{$player['y']}";
            if (isset($allExplosionTiles[$posKey])) {
                $owners = array_values(array_unique($damageOwners[$posKey]));
                sort($owners);
                $eligibleOwners = array_values(array_filter($owners, fn (string $owner): bool => $owner === $pid ? $selfDamage : $friendlyFire));
                if ($eligibleOwners === []) {
                    continue;
                }
                $killerId = $eligibleOwners[0];
                $isOwner = ($killerId === $pid);

                $player['alive'] = false;
                $eliminatedPlayers[] = [
                    'player_id' => $pid,
                    'nickname' => $player['nickname'],
                    'killed_by' => $killerId,
                ];

                // Credit kill to owner if not suicide
                if (! $isOwner && isset($state['players'][$killerId])) {
                    $state['players'][$killerId]['kills']++;
                }
            }
        }
        unset($player);

        foreach ($state['players'] as $pid => &$player) {
            $player['active_bombs'] = count(array_filter($state['bombs'], fn (array $bomb): bool => $bomb['owner_id'] === $pid));
        }
        unset($player);

        // Track stats
        if (! isset($state['stats'])) {
            $state['stats'] = ['total_bombs_detonated' => 0, 'total_blocks_destroyed' => 0, 'total_eliminations' => 0];
        }
        $state['stats']['total_bombs_detonated'] += count($detonatedBombIds);
        $state['stats']['total_blocks_destroyed'] += count($destroyedBlocks);
        $state['stats']['total_eliminations'] += count($eliminatedPlayers);

        // Evaluate match termination rule centrally
        if ($evaluateOutcome) {
            $this->evaluateMatchOutcome($state);
        }

        $state['version']++;

        return [
            'success' => true,
            'root_bomb_id' => $rootBomb['id'],
            'detonated_bomb_ids' => array_values(array_unique($detonatedBombIds)),
            'explosion_tiles' => array_values($allExplosionTiles),
            'destroyed_blocks' => $destroyedBlocks,
            'spawned_powerups' => $spawnedPowerUps,
            'eliminated_players' => $eliminatedPlayers,
            'is_game_over' => $state['is_game_over'],
            'winner_id' => $state['winner_id'],
            'finish_reason' => $state['finish_reason'] ?? null,
        ];
    }

    /**
     * Check and detonate any active bomb whose timer has elapsed.
     */
    public function checkAndDetonateExpiredBombs(array &$state, ?float $currentTime = null): array
    {
        $now = $currentTime ?? (float) now()->format('U.u');
        $results = [];

        if (empty($state['bombs'])) {
            return $results;
        }

        uasort($state['bombs'], fn (array $a, array $b): int => [$a['explodes_at'], $a['y'], $a['x'], $a['owner_id']] <=> [$b['explodes_at'], $b['y'], $b['x'], $b['owner_id']]);
        $bombIds = array_keys($state['bombs']);
        foreach ($bombIds as $id) {
            if (isset($state['bombs'][$id]) && ($state['bombs'][$id]['state'] ?? 'active') === 'active' && $now >= $state['bombs'][$id]['explodes_at']) {
                $res = $this->detonateBomb($state, $id, false);
                if ($res['success']) {
                    $results[] = $res;
                }
            }
        }

        if ($results !== []) {
            $this->evaluateMatchOutcome($state, $now);
            foreach ($results as &$result) {
                $result['is_game_over'] = $state['is_game_over'];
                $result['winner_id'] = $state['winner_id'];
                $result['finish_reason'] = $state['finish_reason'];
            }
            unset($result);
        }

        return $results;
    }

    /**
     * Centralized match outcome evaluator based on parameterized rules.
     * Evaluates:
     * - Case A: Only 1 survivor remaining (from >= 2 initial players).
     * - Case B: All players eliminated simultaneously (draw).
     * - Case C: Time expired (most bombs, or most kills when configured).
     * - Case D: >= 2 players alive -> match MUST continue!
     */
    public function evaluateMatchOutcome(array &$state, ?float $currentTime = null): bool
    {
        if (! empty($state['is_game_over'])) {
            return true;
        }

        $now = $currentTime ?? (float) now()->format('U.u');
        $rules = $state['rules'] ?? [];
        $initialCount = (int) ($state['initial_player_count'] ?? count($state['players']));

        $alivePlayers = array_filter($state['players'], fn ($p) => ! empty($p['alive']));
        $aliveCount = count($alivePlayers);

        // Case A & B: Eliminations
        if ($initialCount >= 2) {
            if ($aliveCount === 1 && ($rules['end_on_last_survivor'] ?? true)) {
                $state['is_game_over'] = true;
                $state['winner_id'] = array_key_first($alivePlayers);
                $state['finish_reason'] = 'LAST_PLAYER_ALIVE';

                return true;
            }

            if ($aliveCount === 0) {
                $state['is_game_over'] = true;
                $state['winner_id'] = null; // draw
                $state['finish_reason'] = 'ALL_PLAYERS_ELIMINATED';

                return true;
            }
        }

        // Case C: Check maximum duration / timeout
        $startedAt = (float) ($state['started_at'] ?? $now);
        $durationSeconds = (int) ($state['duration_seconds'] ?? ($rules['round_duration_seconds'] ?? 180));
        $elapsed = $now - $startedAt;

        if ($elapsed >= $durationSeconds) {
            $state['is_game_over'] = true;
            $timeoutPolicy = $rules['timeout_result'] ?? 'most_bombs';
            $scoreField = $timeoutPolicy === 'most_kills' ? 'kills' : 'bombs_placed';

            if (! empty($alivePlayers)) {
                uasort($alivePlayers, fn ($a, $b) => ($b[$scoreField] ?? 0) <=> ($a[$scoreField] ?? 0));
                $first = reset($alivePlayers);
                $second = next($alivePlayers);
                if ($second && ($first[$scoreField] ?? 0) === ($second[$scoreField] ?? 0)) {
                    $state['winner_id'] = null;
                } else {
                    $state['winner_id'] = $first['id'];
                }
            } else {
                $state['winner_id'] = null; // draw
            }

            $state['finish_reason'] = 'TIME_EXPIRED';

            return true;
        }

        // Case D: Multiple players alive and time remaining -> Must continue
        return false;
    }
}
