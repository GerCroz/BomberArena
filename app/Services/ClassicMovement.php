<?php

namespace App\Services;

class ClassicMovement
{
    public const RADIUS = 0.28;

    /**
     * Integrate one bounded, cardinal movement step in board coordinates.
     */
    public static function step(array $state, array $player, ?string $direction, float $delta): array
    {
        if (! $player['alive'] || $direction === null) {
            return $player;
        }
        $vectors = ['up' => [0, -1], 'down' => [0, 1], 'left' => [-1, 0], 'right' => [1, 0]];
        if (! isset($vectors[$direction])) {
            return $player;
        }
        $x = (float) ($player['position_x'] ?? $player['x']);
        $y = (float) ($player['position_y'] ?? $player['y']);
        $distance = min(0.05, max(0, $delta)) * (float) ($state['rules']['movement_speed'] ?? 4) * (1 + 0.15 * (max(1, $player['speed']) - 1));
        [$dx, $dy] = $vectors[$direction];
        $offset = $dx !== 0 ? round($y) - $y : round($x) - $x;
        $assist = (float) ($state['rules']['corner_assist'] ?? 0.25);
        if (abs($offset) > 0.000001) {
            if (abs($offset) > $assist + 0.000001) {
                return $player;
            }
            $correction = max(-$distance, min($distance, $offset));
            if ($vectors[$direction][0] === 0) {
                $dx = $correction <=> 0;
                $dy = 0;
            } else {
                $dx = 0;
                $dy = $correction <=> 0;
            }
            $distance = abs($correction);
        }
        $steps = max(1, (int) ceil($distance / 0.025));
        for ($step = 0; $step < $steps; $step++) {
            $nextX = $x + $dx * $distance / $steps;
            $nextY = $y + $dy * $distance / $steps;
            if (! self::isFree($state, $player, $nextX, $nextY)) {
                break;
            }
            $x = $nextX;
            $y = $nextY;
        }
        $player['position_x'] = round($x, 6);
        $player['position_y'] = round($y, 6);
        $player['x'] = (int) round($x);
        $player['y'] = (int) round($y);
        $player['direction'] = $direction;

        return $player;
    }

    public static function isFree(array $state, array $player, float $x, float $y): bool
    {
        $radius = self::RADIUS;
        for ($row = (int) floor($y - $radius + 0.5); $row <= (int) floor($y + $radius + 0.5); $row++) {
            for ($column = (int) floor($x - $radius + 0.5); $column <= (int) floor($x + $radius + 0.5); $column++) {
                if (($state['grid'][$row][$column] ?? 1) !== 0) {
                    return false;
                }
            }
        }
        foreach ($state['bombs'] as $bomb) {
            if (abs($x - $bomb['x']) < 0.5 + $radius && abs($y - $bomb['y']) < 0.5 + $radius) {
                if (! in_array($player['id'], $bomb['pass_through_players'] ?? [], true)) {
                    return false;
                }
            }
        }
        if ($state['rules']['player_collisions'] ?? false) {
            foreach ($state['players'] as $other) {
                if ($other['id'] !== $player['id'] && $other['alive'] && abs($x - ($other['position_x'] ?? $other['x'])) < $radius * 2 && abs($y - ($other['position_y'] ?? $other['y'])) < $radius * 2) {
                    return false;
                }
            }
        }

        return true;
    }
}
