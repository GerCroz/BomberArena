<?php

namespace App\Services;

class BomberBotService
{
    public function __construct(
        protected BomberGameEngine $engine
    ) {}

    /**
     * Process AI behaviors for all active bots in the match.
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, array<int, mixed>>  $events
     */
    public function processBots(array &$state, array &$events, float $now): void
    {
        if (! empty($state['is_game_over']) || empty($state['players'])) {
            return;
        }

        $dangerMap = $this->calculateDangerMap($state);
        $movesExecuted = 0;

        foreach ($state['players'] as $playerId => &$player) {
            if (! str_starts_with($playerId, 'bot_') || empty($player['alive'])) {
                continue;
            }

            // Keep alive timestamp refreshed
            $player['last_seen_at'] = $now;

            $nextActionAt = (float) ($state['bot_next_action'][$playerId] ?? 0);
            if ($now < $nextActionAt) {
                continue;
            }

            $actionTaken = $this->stepBot($state, $events, $playerId, $dangerMap, $now);
            if ($actionTaken) {
                $movesExecuted++;
            }

            // Human-like cadence: 380ms - 520ms between steps
            $state['bot_next_action'][$playerId] = $now + (mt_rand(38, 52) / 100);
        }
        unset($player);

        if ($movesExecuted > 0) {
            $state['version']++;
        }
    }

    /**
     * Build coordinates of tiles covered by active bombs or explosion zones.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, bool>
     */
    protected function calculateDangerMap(array $state): array
    {
        $danger = [];
        $cols = (int) ($state['rules']['board_cols'] ?? 13);
        $rows = (int) ($state['rules']['board_rows'] ?? 11);
        $grid = $state['grid'] ?? [];

        // Explosions currently active
        foreach ($state['explosions'] ?? [] as $exp) {
            foreach ($exp['affected_tiles'] ?? [] as $tile) {
                $danger["{$tile['x']}_{$tile['y']}"] = true;
            }
        }

        // Bombs and their predicted blast lines
        $dirs = [[0, -1], [0, 1], [-1, 0], [1, 0]];
        foreach ($state['bombs'] ?? [] as $bomb) {
            $bx = (int) $bomb['x'];
            $by = (int) $bomb['y'];
            $range = (int) ($bomb['range'] ?? 2);
            $danger["{$bx}_{$by}"] = true;

            foreach ($dirs as [$dx, $dy]) {
                for ($step = 1; $step <= $range; $step++) {
                    $nx = $bx + $dx * $step;
                    $ny = $by + $dy * $step;

                    if ($nx < 0 || $nx >= $cols || $ny < 0 || $ny >= $rows) {
                        break;
                    }

                    $cell = $grid[$ny][$nx] ?? BomberGameEngine::TILE_WALL;
                    if ($cell === BomberGameEngine::TILE_WALL) {
                        break;
                    }

                    $danger["{$nx}_{$ny}"] = true;

                    if ($cell === BomberGameEngine::TILE_BLOCK) {
                        break;
                    }
                }
            }
        }

        return $danger;
    }

    /**
     * Run single decision cycle for an individual bot.
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, array<int, mixed>>  $events
     * @param  array<string, bool>  $dangerMap
     */
    protected function stepBot(array &$state, array &$events, string $botId, array $dangerMap, float $now): bool
    {
        $player = &$state['players'][$botId];
        $px = (int) $player['x'];
        $py = (int) $player['y'];
        $grid = $state['grid'] ?? [];
        $cols = (int) ($state['rules']['board_cols'] ?? 13);
        $rows = (int) ($state['rules']['board_rows'] ?? 11);

        $directions = [
            'up' => ['dir' => 'up', 'dx' => 0, 'dy' => -1],
            'down' => ['dir' => 'down', 'dx' => 0, 'dy' => 1],
            'left' => ['dir' => 'left', 'dx' => -1, 'dy' => 0],
            'right' => ['dir' => 'right', 'dx' => 1, 'dy' => 0],
        ];

        // Existing bomb tile lookup
        $bombTiles = [];
        foreach ($state['bombs'] ?? [] as $b) {
            $bombTiles["{$b['x']}_{$b['y']}"] = true;
        }

        // Find walkable neighbors
        $walkable = [];
        $safeWalkable = [];
        $adjacentToBlock = false;
        $adjacentToEnemy = false;

        foreach ($directions as $key => $d) {
            $nx = $px + $d['dx'];
            $ny = $py + $d['dy'];

            if ($nx < 1 || $nx >= $cols - 1 || $ny < 1 || $ny >= $rows - 1) {
                continue;
            }

            $cell = $grid[$ny][$nx] ?? BomberGameEngine::TILE_WALL;
            if ($cell === BomberGameEngine::TILE_BLOCK) {
                $adjacentToBlock = true;
            }

            // Check if enemy nearby
            foreach ($state['players'] as $otherId => $other) {
                if ($otherId !== $botId && ($other['alive'] ?? false) && (int) $other['x'] === $nx && (int) $other['y'] === $ny) {
                    $adjacentToEnemy = true;
                }
            }

            if ($cell === BomberGameEngine::TILE_EMPTY && ! isset($bombTiles["{$nx}_{$ny}"])) {
                $walkable[$key] = $d;
                if (! isset($dangerMap["{$nx}_{$ny}"])) {
                    $safeWalkable[$key] = $d;
                }
            }
        }

        $isCurrentlyInDanger = isset($dangerMap["{$px}_{$py}"]);

        // 1. ESCAPE BEHAVIOR: If standing in danger, evade immediately!
        if ($isCurrentlyInDanger) {
            $escapeDirs = ! empty($safeWalkable) ? $safeWalkable : $walkable;
            if (! empty($escapeDirs)) {
                $chosen = array_rand($escapeDirs);

                return $this->executeMove($state, $events, $botId, $chosen);
            }

            return false;
        }

        // 2. BOMB PLACEMENT: If safe, next to blocks/enemies, and has bomb capacity
        $activeBombs = (int) ($player['active_bombs'] ?? 0);
        $maxBombs = (int) ($player['max_bombs'] ?? 1);
        $canPlant = $activeBombs < $maxBombs && ! isset($bombTiles["{$px}_{$py}"]);

        // Ensure bot has at least one escape path before planting
        if ($canPlant && ($adjacentToBlock || $adjacentToEnemy) && count($safeWalkable) >= 1) {
            if (mt_rand(1, 100) <= 65) {
                $bombRes = $this->engine->handlePlaceBomb($state, $botId, $now);
                if (! empty($bombRes['success'])) {
                    $events[] = ['bomb_placed', ['bomb' => $bombRes['bomb']]];

                    // Immediately step into a safe neighbor to flee the bomb
                    $escapeDir = array_rand($safeWalkable);
                    $this->executeMove($state, $events, $botId, $escapeDir);

                    return true;
                }
            }
        }

        // 3. ROAMING & POWERUP SEEKING:
        if (empty($safeWalkable)) {
            return false;
        }

        // Prefer neighbor with powerup
        foreach ($safeWalkable as $dirKey => $d) {
            $nx = $px + $d['dx'];
            $ny = $py + $d['dy'];
            if (isset($state['powerups']["{$nx}_{$ny}"])) {
                return $this->executeMove($state, $events, $botId, $dirKey);
            }
        }

        // Continue in current direction if safe and walkable (prevents erratic back-and-forth)
        $currentDir = $player['direction'] ?? 'down';
        if (isset($safeWalkable[$currentDir]) && mt_rand(1, 100) <= 65) {
            return $this->executeMove($state, $events, $botId, $currentDir);
        }

        // Otherwise pick random safe direction
        $chosenDir = array_rand($safeWalkable);

        return $this->executeMove($state, $events, $botId, $chosenDir);
    }

    /**
     * Execute move and dispatch relevant events.
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, array<int, mixed>>  $events
     */
    protected function executeMove(array &$state, array &$events, string $botId, string $direction): bool
    {
        $res = $this->engine->handleMove($state, $botId, $direction);
        if (! empty($res['success'])) {
            $p = $state['players'][$botId];
            $events[] = ['player_moved', [
                'player_id' => $botId,
                'x' => $p['x'],
                'y' => $p['y'],
                'direction' => $p['direction'],
                'stats' => $p,
                'collected_powerup' => $res['collected_powerup'] ?? null,
                'collected_keys' => ! empty($res['collected_powerup']) ? ["{$p['x']}_{$p['y']}"] : [],
                'tick' => (int) ($state['tick'] ?? 0),
            ]];

            if (! empty($res['collected_powerup'])) {
                $events[] = ['powerup_collected', [
                    'player_id' => $botId,
                    'type' => $res['collected_powerup'],
                    'x' => $p['x'],
                    'y' => $p['y'],
                ]];
            }

            return true;
        }

        return false;
    }
}
