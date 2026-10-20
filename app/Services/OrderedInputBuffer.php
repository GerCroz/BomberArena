<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class OrderedInputBuffer
{
    public static function key(string $room, string $round, string $player): string
    {
        return 'arena_frames_'.$room.'_'.$round.'_'.$player;
    }

    public static function read(string $room, string $round, string $player): array
    {
        return Cache::store(config('cache.arena_input_store', 'file'))->get(self::key($room, $round, $player), []);
    }

    public static function valid(array $frames): bool
    {
        if (count($frames) < 1 || count($frames) > 24) {
            return false;
        }
        $now = (float) now()->format('U.u');
        foreach ($frames as $frame) {
            if (! is_array($frame) || ! is_int($frame['sequence'] ?? null) || $frame['sequence'] < 1 || $frame['sequence'] > 2147483647
                || ! is_numeric($frame['duration'] ?? null) || ! is_finite((float) $frame['duration']) || $frame['duration'] < 0 || $frame['duration'] > 0.050001
                || ! array_key_exists('direction', $frame) || ! in_array($frame['direction'], [null, 'up', 'down', 'left', 'right'], true)
                || ! is_numeric($frame['sampled_at'] ?? null) || ! is_finite((float) $frame['sampled_at']) || $frame['sampled_at'] > $now + 2.0
                || (isset($frame['action_id']) && (! is_string($frame['action_id']) || ! preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $frame['action_id'])))
                || (isset($frame['reset']) && (! is_bool($frame['reset']) || ($frame['reset'] && ($frame['direction'] !== null || $frame['duration'] != 0 || isset($frame['action_id'])))))
                || (isset($frame['to_x']) && (! is_int($frame['to_x']) || $frame['to_x'] < 0 || $frame['to_x'] >= 13))
                || (isset($frame['to_y']) && (! is_int($frame['to_y']) || $frame['to_y'] < 0 || $frame['to_y'] >= 11))
                || (isset($frame['from_x']) && (! is_int($frame['from_x']) || $frame['from_x'] < 0 || $frame['from_x'] >= 13))
                || (isset($frame['from_y']) && (! is_int($frame['from_y']) || $frame['from_y'] < 0 || $frame['from_y'] >= 11))) {
                return false;
            }
        }

        return true;
    }

    public static function put(string $room, string $round, string $player, array $frames, bool $singleWriter = false): bool
    {
        if (! self::valid($frames)) {
            return false;
        }
        $store = Cache::store(config('cache.arena_input_store', 'file'));
        $key = self::key($room, $round, $player);
        $stored = false;
        $write = function () use ($store, $key, $frames, &$stored): void {
            $pending = $store->get($key, []);
            foreach ($frames as $frame) {
                $pending[$frame['sequence']] ??= array_intersect_key($frame, array_flip(['sequence', 'duration', 'direction', 'sampled_at', 'action_id', 'reset', 'to_x', 'to_y', 'from_x', 'from_y']));
            }
            ksort($pending, SORT_NUMERIC);
            $store->put($key, array_slice($pending, -24, null, true), 2);
            $stored = true;
        };
        if ($singleWriter && config('cache.stores.'.config('cache.arena_input_store', 'file').'.driver') === 'file') {
            $write();
        } else {
            $store->lock($key.'_lock', 2)->get($write);
        }

        return $stored;
    }

    public static function merge(array &$state, string $player, array $frames, float $time): void
    {
        if ($frames === [] || ! ($state['players'][$player]['alive'] ?? false)) {
            return;
        }
        $state['ordered_stream_active'] = true;
        $pending = $state['ordered_inputs'][$player] ?? [];
        $ack = $state['input_ack'][$player] ?? 0;
        foreach ($frames as $frame) {
            if ($frame['sequence'] <= $ack) {
                continue;
            }
            if (! empty($frame['reset'])) {
                $pending = array_filter($pending, fn (array $entry): bool => $entry['sequence'] > $frame['sequence']);
                $state['input_reset_floor'][$player] = max($state['input_reset_floor'][$player] ?? 0, $frame['sequence']);
            }
            if ($frame['sequence'] < ($state['input_reset_floor'][$player] ?? 0)) {
                continue;
            }
            $pending[$frame['sequence']] ??= $frame;
        }
        ksort($pending, SORT_NUMERIC);
        $state['ordered_inputs'][$player] = array_slice($pending, -24, null, true);
        $state['players'][$player]['last_seen_at'] = $time;
    }

    public static function snapshot(array $state): array
    {
        unset($state['ordered_inputs'], $state['input_credit'], $state['input_credit_at'], $state['seen_actions'], $state['input_reset_floor'], $state['diagnostic_window']);

        return $state;
    }
}
