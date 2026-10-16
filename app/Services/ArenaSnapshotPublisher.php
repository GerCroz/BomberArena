<?php

namespace App\Services;

use App\Events\GameRoomEvent;
use Illuminate\Support\Str;

class ArenaSnapshotPublisher
{
    private array $sent = [];

    public function publish(string $room, string $round, ?array $candidate = null): void
    {
        $candidate ??= ArenaStateStore::get($room);
        $key = 'arena_sent_snapshot_'.$room.'_'.$round;
        $recent = $this->sent[$key] ?? null;
        if (! $candidate || ($recent && ($candidate['version'] <= $recent['version'] || (float) now()->format('U.u') - $recent['time'] < 1 / (int) ($candidate['rules']['publish_hz'] ?? 10)))) {
            return;
        }
        $cache = ArenaStateStore::cache();
        $cache->lock('arena_publish_'.$room, 15)->get(function () use ($cache, $room, $round): void {
            $state = ArenaStateStore::get($room);
            if (! $state || $state['round_id'] !== $round) {
                return;
            }
            $key = 'arena_sent_snapshot_'.$room.'_'.$round;
            $previous = $cache->get($key, ['version' => 0, 'time' => 0]);
            $this->sent[$key] = $previous;
            $time = (float) now()->format('U.u');
            if ($state['version'] <= $previous['version'] || $time - $previous['time'] < 1 / (int) ($state['rules']['publish_hz'] ?? 10)) {
                return;
            }
            broadcast(new GameRoomEvent($room, 'state_updated', [
                'state' => OrderedInputBuffer::snapshot($state),
                'round_id' => $round,
                'version' => $state['version'],
                'server_time' => microtime(true),
                'event_id' => (string) Str::uuid(),
            ]));
            $cache->put($key, ['version' => $state['version'], 'time' => $time], 1800);
            $this->sent[$key] = ['version' => $state['version'], 'time' => $time];
        });
    }
}
