<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Laravel\Reverb\Events\MessageReceived;

class ArenaInputService
{
    public static function token(string $room, string $player): string
    {
        return hash_hmac('sha256', 'arena-input-v2|'.$room.'|'.$player, (string) config('app.key'));
    }

    public static function key(string $room, string $round, string $player): string
    {
        return 'arena_latest_input_'.$room.'_'.$round.'_'.$player;
    }

    /**
     * Reverb emits this after handling the heartbeat. Its application payload
     * is consumed here and is never forwarded as a public client event.
     */
    public function receive(MessageReceived $event): void
    {
        if (strlen($event->message) > 16384) {
            return;
        }
        $message = json_decode($event->message, true);
        if (($message['event'] ?? null) !== 'pusher:ping') {
            return;
        }
        $data = $message['data'] ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (is_array($data['arena_input'] ?? null)) {
            $this->store($data['arena_input']);
        }
    }

    public function store(array $input): bool
    {
        $room = $input['room'] ?? '';
        $player = $input['player'] ?? '';
        $round = $input['round_id'] ?? '';
        $sequence = $input['sequence'] ?? null;
        if (! is_string($room) || ! preg_match('/^[A-Z0-9]{4,8}$/', $room) || ! is_string($player) || ! preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $player) || ! is_string($round) || ! preg_match('/^[a-f0-9]{32}$/', $round) || ! is_int($sequence) || $sequence < 1 || $sequence > 2147483647) {
            return false;
        }
        if (! is_string($input['token'] ?? null) || ! hash_equals(self::token($room, $player), $input['token'])) {
            return false;
        }
        if (isset($input['frames'])) {
            return is_array($input['frames']) && OrderedInputBuffer::put($room, $round, $player, $input['frames'], singleWriter: true);
        }
        if (! array_key_exists('direction', $input) || ! in_array($input['direction'], [null, 'up', 'down', 'left', 'right'], true) || ! is_numeric($input['sampled_at'] ?? null)) {
            return false;
        }
        $now = (float) now()->format('U.u');
        $sampled = (float) $input['sampled_at'];
        if ($sampled > $now + 2.0 || ($input['direction'] !== null && $now - $sampled > 3.0)) {
            return false;
        }
        $key = self::key($room, $round, $player);
        $stored = false;
        Cache::lock($key.'_lock', 1)->get(function () use ($key, $input, $now, $sampled, &$stored): void {
            $previous = Cache::get($key);
            if (($previous['sequence'] ?? 0) >= $input['sequence']) {
                return;
            }
            Cache::put($key, ['direction' => $input['direction'], 'sequence' => $input['sequence'], 'received_at' => $now, 'expires_at' => $input['direction'] === null ? $now : min($now + 0.5, $sampled + 0.5)], 2);
            $stored = true;
        });

        return $stored;
    }
}
