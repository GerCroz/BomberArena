<?php

namespace App\Services;

class BomberGameEngine
{
    public const WIDTH = 13;
    public const HEIGHT = 11;

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
        // Bottom-right
        ['x' => 11, 'y' => 9], ['x' => 11, 'y' => 8], ['x' => 10, 'y' => 9],
        // Top-right
        ['x' => 11, 'y' => 1], ['x' => 10, 'y' => 1], ['x' => 11, 'y' => 2],
        // Bottom-left
        ['x' => 1, 'y' => 9], ['x' => 2, 'y' => 9], ['x' => 1, 'y' => 8],
    ];

    /**
     * Generate an initial map and state for a room.
     */
    public function createInitialState(array $playerRecords, ?int $seed = null): array
    {
        $seed = $seed ?? mt_rand(10000, 99999);
        mt_srand($seed);

        $grid = [];
        $powerups = []; // key: "x_y" => type

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

                // Destructible block distribution (~65% chance)
                if (mt_rand(1, 100) <= 65) {
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

        foreach ($playerRecords as $index => $record) {
            $slot = $record['slot_index'] ?? $index;
            $spawn = self::SPAWN_POSITIONS[$slot] ?? ['x' => 1, 'y' => 1];

            $players[$record['player_id']] = [
                'id' => $record['player_id'],
                'nickname' => $record['nickname'] ?? ('Player ' . ($slot + 1)),
                'color' => $colors[$slot % 4],
                'slot' => $slot,
                'x' => $spawn['x'],
                'y' => $spawn['y'],
                'direction' => 'down',
                'alive' => true,
                'speed' => 1,
                'max_bombs' => 1,
                'bomb_range' => 2,
                'active_bombs' => 0,
                'kills' => 0,
                'bombs_placed' => 0,
                'powerups_collected' => 0,
            ];
        }

        return [
            'seed' => $seed,
            'version' => 1,
            'grid' => $grid,
            'players' => $players,
            'bombs' => [], // id => [id, x, y, owner_id, range, planted_at, explodes_at]
            'explosions' => [], // list of active explosion tiles with expiration
            'powerups' => $powerups, // "x_y" => 'speed'|'bomb'|'fire'
            'winner_id' => null,
            'is_game_over' => false,
            'started_at' => microtime(true),
        ];
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
        if (!isset($state['players'][$playerId])) {
            return ['success' => false, 'error' => 'Jugador no encontrado'];
        }

        $player = &$state['players'][$playerId];
        if (!$player['alive']) {
            return ['success' => false, 'error' => 'Jugador eliminado'];
        }

        $dx = 0;
        $dy = 0;
        switch ($direction) {
            case 'up': $dy = -1; break;
            case 'down': $dy = 1; break;
            case 'left': $dx = -1; break;
            case 'right': $dx = 1; break;
            default: return ['success' => false, 'error' => 'Dirección inválida'];
        }

        $newX = $player['x'] + $dx;
        $newY = $player['y'] + $dy;

        // Check map boundaries
        if ($newX < 0 || $newX >= self::WIDTH || $newY < 0 || $newY >= self::HEIGHT) {
            return ['success' => false, 'error' => 'Fuera de límites'];
        }

        // Check solid tiles
        $tile = $state['grid'][$newY][$newX] ?? self::TILE_WALL;
        if ($tile === self::TILE_WALL || $tile === self::TILE_BLOCK) {
            return ['success' => false, 'error' => 'Casilla bloqueada por obstáculo'];
        }

        // Check bombs: A player can walk away from a bomb they are currently on,
        // but cannot walk INTO a bomb tile.
        foreach ($state['bombs'] as $bomb) {
            if ($bomb['x'] === $newX && $bomb['y'] === $newY) {
                // Cannot enter bomb
                return ['success' => false, 'error' => 'Casilla bloqueada por bomba'];
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
     * Plant a bomb by player.
     */
    public function handlePlaceBomb(array &$state, string $playerId): array
    {
        if (!isset($state['players'][$playerId])) {
            return ['success' => false, 'error' => 'Jugador no encontrado'];
        }

        $player = &$state['players'][$playerId];
        if (!$player['alive']) {
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

        $bombId = 'bomb_' . uniqid() . '_' . mt_rand(100, 999);
        $plantedAt = microtime(true);
        $explodesAt = $plantedAt + 2.0; // 2 seconds fuse

        $bomb = [
            'id' => $bombId,
            'x' => $bx,
            'y' => $by,
            'owner_id' => $playerId,
            'range' => $player['bomb_range'],
            'planted_at' => $plantedAt,
            'explodes_at' => $explodesAt,
        ];

        $state['bombs'][$bombId] = $bomb;
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
    public function detonateBomb(array &$state, string $bombId): array
    {
        if (!isset($state['bombs'][$bombId])) {
            return ['success' => false, 'error' => 'Bomba no encontrada'];
        }

        $rootBomb = $state['bombs'][$bombId];
        unset($state['bombs'][$bombId]);

        $bombsToDetonate = [$rootBomb];
        $allExplosionTiles = [];
        $destroyedBlocks = [];
        $spawnedPowerUps = [];
        $eliminatedPlayers = [];

        // Breadth-first explosion resolution for chain reactions
        while (!empty($bombsToDetonate)) {
            $currentBomb = array_shift($bombsToDetonate);
            $cx = $currentBomb['x'];
            $cy = $currentBomb['y'];
            $range = $currentBomb['range'];
            $ownerId = $currentBomb['owner_id'];

            // Center tile
            $allExplosionTiles["{$cx}_{$cy}"] = ['x' => $cx, 'y' => $cy];

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

                        // Chance to spawn power-up (~35%)
                        if (mt_rand(1, 100) <= 35) {
                            $types = [self::POWERUP_SPEED, self::POWERUP_BOMB, self::POWERUP_FIRE];
                            $chosen = $types[array_rand($types)];
                            $state['powerups']["{$tx}_{$ty}"] = $chosen;
                            $spawnedPowerUps[] = ['x' => $tx, 'y' => $ty, 'type' => $chosen];
                        }
                        break; // stops propagation in this direction
                    }

                    // Empty tile: fire continues
                    $allExplosionTiles["{$tx}_{$ty}"] = ['x' => $tx, 'y' => $ty];

                    // Check if another active bomb is in this tile -> CHAIN REACTION!
                    foreach ($state['bombs'] as $otherId => $otherBomb) {
                        if ($otherBomb['x'] === $tx && $otherBomb['y'] === $ty) {
                            unset($state['bombs'][$otherId]);
                            $bombsToDetonate[] = $otherBomb;
                        }
                    }
                }
            }
        }

        // Check player eliminations against all explosion tiles
        foreach ($state['players'] as $pid => &$player) {
            if (!$player['alive']) {
                continue;
            }

            $posKey = "{$player['x']}_{$player['y']}";
            if (isset($allExplosionTiles[$posKey])) {
                $player['alive'] = false;
                $eliminatedPlayers[] = [
                    'player_id' => $pid,
                    'nickname' => $player['nickname'],
                    'killed_by' => $rootBomb['owner_id'],
                ];

                // Credit kill to owner if not suicide
                if ($rootBomb['owner_id'] !== $pid && isset($state['players'][$rootBomb['owner_id']])) {
                    $state['players'][$rootBomb['owner_id']]['kills']++;
                }
            }
        }
        unset($player);

        // Check Game Over condition
        $alivePlayers = array_filter($state['players'], fn($p) => $p['alive']);
        $aliveCount = count($alivePlayers);

        $state['version']++;

        if ($aliveCount <= 1) {
            $state['is_game_over'] = true;
            $state['winner_id'] = $aliveCount === 1 ? array_key_first($alivePlayers) : null; // null means draw
        }

        return [
            'success' => true,
            'root_bomb_id' => $rootBomb['id'],
            'explosion_tiles' => array_values($allExplosionTiles),
            'destroyed_blocks' => $destroyedBlocks,
            'spawned_powerups' => $spawnedPowerUps,
            'eliminated_players' => $eliminatedPlayers,
            'is_game_over' => $state['is_game_over'],
            'winner_id' => $state['winner_id'],
        ];
    }
}
