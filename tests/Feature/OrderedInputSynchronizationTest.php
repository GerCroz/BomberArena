<?php

namespace Tests\Feature;

use App\Events\GameRoomEvent;
use App\Services\ArenaInputService;
use App\Services\ArenaSnapshotPublisher;
use App\Services\ArenaStateStore;
use App\Services\BomberGameEngine;
use App\Services\OrderedInputBuffer;
use App\Services\RoomService;
use App\Services\RoomSocialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OrderedInputSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([1, 30])]
    #[TestWith([2, 100])]
    #[TestWith([3, 200])]
    #[TestWith([4, 300])]
    public function test_simultaneous_finite_inputs_remain_independent_without_backward_steps(int $players, int $rtt): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $this->state($players);
        $start = $state['started_at'];
        $network = [];
        $last = array_fill(0, $players, 1.0);
        for ($tick = 1; $tick <= 210; $tick++) {
            $time = $start + $tick * 0.05;
            if ($tick <= 200) {
                for ($id = 0; $id < $players; $id++) {
                    $network[] = ['at' => $time + $rtt / 2000 + ($tick % 3) * 0.005, 'id' => 'p'.$id, 'frame' => $this->frame($tick, $time)];
                }
            }
            foreach ($network as $index => $packet) {
                if ($packet['at'] <= $time + 0.000001) {
                    OrderedInputBuffer::merge($state, $packet['id'], [$packet['frame']], $time);
                    unset($network[$index]);
                }
            }
            $engine->advanceMovement($state, $time);
            for ($id = 0; $id < $players; $id++) {
                $x = $state['players']['p'.$id]['position_x'] ?? 1;
                $this->assertGreaterThanOrEqual($last[$id] - 0.000001, $x);
                $last[$id] = $x;
            }
        }
        for ($id = 0; $id < $players; $id++) {
            $this->assertEqualsWithDelta(41, $last[$id], 0.0001);
            $this->assertSame(200, $state['input_ack']['p'.$id]);
        }
        $engine->advanceMovement($state, $start + 20);
        $this->assertSame($last[0], $state['players']['p0']['position_x']);
    }

    public function test_bomb_is_placed_between_prior_and_subsequent_motion_and_retries_do_not_duplicate_it(): void
    {
        $state = $this->state(2);
        $state['players']['p0']['x'] = 4;
        $state['players']['p0']['y'] = 5;
        $time = $state['started_at'];
        $frames = [$this->frame(1, $time), [...$this->frame(2, $time), 'duration' => 0, 'direction' => null, 'action_id' => 'bomb_action_1']];
        for ($sequence = 3; $sequence <= 7; $sequence++) {
            $frames[] = $this->frame($sequence, $time);
        }
        OrderedInputBuffer::merge($state, 'p0', $frames, $time);
        $this->assertSame(0, $state['input_ack']['p0'] ?? 0);
        $engine = new BomberGameEngine;
        for ($tick = 1; $tick <= 6; $tick++) {
            $engine->advanceMovement($state, $time + $tick * 0.05);
            OrderedInputBuffer::merge($state, 'p0', $frames, $time + $tick * 0.05);
        }
        $bomb = array_values($state['bombs'])[0];
        $this->assertSame([4, 5], [$bomb['x'], $bomb['y']]);
        $this->assertEqualsWithDelta(5.2, $state['players']['p0']['position_x'], 0.000001);
        $this->assertSame(2, $state['action_results']['p0']['bomb_action_1']['sequence']);
        OrderedInputBuffer::merge($state, 'p0', [[...$this->frame(8, $time), 'duration' => 0, 'action_id' => 'bomb_action_1']], $time);
        $engine->advanceMovement($state, $time + 0.35);
        $this->assertCount(1, $state['bombs']);
    }

    public function test_two_players_bomb_simultaneously_without_shared_positions_or_duplicate_actions(): void
    {
        $state = $this->state(2);
        $time = $state['started_at'];
        foreach (['p1', 'p0'] as $id) {
            OrderedInputBuffer::merge($state, $id, [$this->frame(1, $time), [...$this->frame(2, $time), 'duration' => 0, 'action_id' => 'bomb_'.$id.'_action']], $time);
        }
        (new BomberGameEngine)->advanceMovement($state, $time + 0.05);
        $this->assertCount(2, $state['bombs']);
        $this->assertSame([1, 1], array_column(array_values($state['bombs']), 'x'));
        $this->assertNotSame($state['players']['p0']['y'], $state['players']['p1']['y']);
    }

    public function test_expired_frames_and_reconnect_reset_cannot_replay_a_movement_burst(): void
    {
        $state = $this->state(2);
        $time = $state['started_at'];
        for ($seq = 1; $seq <= 24; $seq++) {
            OrderedInputBuffer::merge($state, 'p0', [$this->frame($seq, $time)], $time);
        }
        $engine = new BomberGameEngine;
        $engine->advanceMovement($state, $time + 3);
        $this->assertSame(1, $state['players']['p0']['x']);
        $this->assertSame(24, $state['input_ack']['p0']);
        OrderedInputBuffer::merge($state, 'p0', [$this->frame(25, $time + 3)], $time + 3);
        OrderedInputBuffer::merge($state, 'p0', [[...$this->frame(26, $time + 3), 'duration' => 0, 'direction' => null, 'reset' => true]], $time + 3);
        $engine->advanceMovement($state, $time + 3.05);
        $this->assertSame(1, $state['players']['p0']['x']);
        $this->assertSame(26, $state['input_ack']['p0']);
    }

    public function test_mailboxes_validate_frames_and_isolate_room_round_and_player(): void
    {
        config(['cache.arena_input_store' => 'array']);
        $this->freezeTime();
        $time = (float) now()->format('U.u');
        $this->assertTrue(OrderedInputBuffer::put('ROOMA', 'round1', 'p0', [$this->frame(1, $time)]));
        $this->assertSame([], OrderedInputBuffer::read('ROOMB', 'round1', 'p0'));
        $this->assertSame([], OrderedInputBuffer::read('ROOMA', 'round2', 'p0'));
        $this->assertSame([], OrderedInputBuffer::read('ROOMA', 'round1', 'p1'));
        $this->assertFalse(OrderedInputBuffer::put('ROOMA', 'round1', 'p0', [[...$this->frame(2, $time), 'duration' => 100]]));
        $this->assertFalse(OrderedInputBuffer::put('ROOMA', 'round1', 'p0', [[...$this->frame(2, $time), 'sampled_at' => $time + 5]]));
    }

    public function test_http_acknowledges_only_simulated_frames_and_hides_private_queues(): void
    {
        config(['cache.arena_input_store' => 'array']);
        Event::fake([GameRoomEvent::class]);
        $this->freezeTime();
        $service = app(RoomService::class);
        $room = $service->createRoom('host_player', 'Host');
        $service->joinRoom($room->code, 'guest_player', 'Guest');
        $state = $service->startGame($room->code, 'host_player')['state'];
        $state['grid'][1][2] = 0;
        Cache::put('arena_state_'.$room->code, $state, 600);
        $payload = ['round_id' => $state['round_id'], 'sequence' => 1, 'sampled_at' => $state['started_at'], 'frames' => [$this->frame(1, $state['started_at'])]];
        $this->withHeader('X-Player-ID', 'host_player')->postJson('/api/rooms/'.$room->code.'/input', $payload)->assertOk()->assertJsonMissingPath('state.ordered_inputs');
        $this->assertSame(0, Cache::get('arena_state_'.$room->code)['input_ack']['host_player'] ?? 0);
        $this->travel(50)->milliseconds();
        $response = $service->tickRoom($room->code);
        $this->assertSame(1, $response['state']['input_ack']['host_player']);
        $this->assertEqualsWithDelta(1.2, $response['state']['players']['host_player']['position_x'], 0.000001);
        $this->assertArrayNotHasKey('ordered_inputs', $response['state']);
        $this->withHeader('X-Player-ID', 'host_player')->postJson('/api/rooms/'.$room->code.'/input', [...$payload, 'round_id' => 'old-round'])->assertUnprocessable();
    }

    #[TestWith([75])]
    #[TestWith([120])]
    #[TestWith([170])]
    public function test_irregular_worker_intervals_do_not_starve_input_time_or_accumulate_idle_commands(int $intervalMs): void
    {
        $state = $this->state(3);
        $start = $state['started_at'];
        $engine = new BomberGameEngine;
        $engine->advanceMovement($state, $start + 3);
        $generated = 0;
        $last = 1;
        for ($iteration = 1; $iteration <= 100; $iteration++) {
            $time = $start + 3 + $iteration * $intervalMs / 1000;
            $frames = [];
            while (($generated + 1) * 0.05 <= $iteration * $intervalMs / 1000 + 0.000001) {
                $generated++;
                $frames[] = $this->frame($generated, $start + 3 + $generated * 0.05);
            }
            OrderedInputBuffer::merge($state, 'p0', $frames, $time);
            $engine->advanceMovement($state, $time);
            $position = $state['players']['p0']['position_x'];
            $this->assertGreaterThanOrEqual($last, $position);
            $this->assertLessThanOrEqual(0.800001, $position - $last);
            $last = $position;
        }
        $this->assertEqualsWithDelta(1 + 4 * $intervalMs / 10, $last, 0.0001);
        $this->assertSame((int) round($intervalMs * 2), $state['input_ack']['p0']);
    }

    public function test_three_players_keep_different_directions_for_ten_seconds(): void
    {
        $state = $this->state(3);
        $state['grid'] = array_fill(0, 100, array_fill(0, 100, 0));
        $state['players']['p1']['x'] = 80;
        $state['players']['p2']['y'] = 5;
        $directions = ['p0' => 'right', 'p1' => 'left', 'p2' => 'down'];
        $time = $state['started_at'];
        $engine = new BomberGameEngine;
        for ($tick = 1; $tick <= 200; $tick++) {
            foreach ($directions as $id => $direction) {
                OrderedInputBuffer::merge($state, $id, [[...$this->frame($tick, $time + $tick * 0.05), 'direction' => $direction]], $time + $tick * 0.05);
            }
            $engine->advanceMovement($state, $time + $tick * 0.05);
        }
        $this->assertEqualsWithDelta(41, $state['players']['p0']['position_x'], 0.0001);
        $this->assertEqualsWithDelta(40, $state['players']['p1']['position_x'], 0.0001);
        $this->assertEqualsWithDelta(45, $state['players']['p2']['position_y'], 0.0001);
    }

    public function test_ordered_simulation_cost_for_one_to_four_players_and_multiple_rooms(): void
    {
        $engine = new BomberGameEngine;
        $measurements = [];
        foreach ([[1, 1], [2, 1], [3, 1], [4, 1], [4, 4]] as [$players, $rooms]) {
            $states = array_fill(0, $rooms, $this->state($players));
            $base = $states[0]['started_at'];
            $samples = [];
            $started = hrtime(true);
            for ($tick = 1; $tick <= 200; $tick++) {
                $time = $base + $tick * 0.05;
                $tickStarted = hrtime(true);
                foreach ($states as &$state) {
                    for ($index = 0; $index < $players; $index++) {
                        OrderedInputBuffer::merge($state, 'p'.$index, [$this->frame($tick, $time)], $time);
                    }
                    $engine->advanceMovement($state, $time);
                }
                unset($state);
                $samples[] = (hrtime(true) - $tickStarted) / 1_000_000;
            }
            sort($samples);
            $measurements[] = ['players_per_room' => $players, 'rooms' => $rooms, 'simulated_seconds' => 10, 'mean_tick_ms' => array_sum($samples) / count($samples), 'p95_tick_ms' => $samples[189], 'max_tick_ms' => max($samples), 'wall_ms' => (hrtime(true) - $started) / 1_000_000, 'peak_process_memory_bytes' => memory_get_peak_usage(true)];
            foreach ($states as $state) {
                for ($index = 0; $index < $players; $index++) {
                    $this->assertEqualsWithDelta(41, $state['players']['p'.$index]['position_x'], 0.0001);
                }
            }
        }
        if (getenv('BOMBER_SYNC_METRICS') === '1') {
            file_put_contents(base_path('BomberArena-synchronization-metrics.json'), json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->assertCount(5, $measurements);
    }

    public function test_dedicated_state_store_preserves_existing_state_and_is_used_for_http_and_simulation(): void
    {
        config(['cache.stores.arena_isolated' => ['driver' => 'array'], 'cache.arena_state_store' => 'arena_isolated']);
        Event::fake([GameRoomEvent::class]);
        $this->freezeTime();
        $service = app(RoomService::class);
        $room = $service->createRoom('host_player', 'Host');
        $service->joinRoom($room->code, 'guest_player', 'Guest');
        $state = $service->startGame($room->code, 'host_player')['state'];
        $this->assertNull(Cache::get('arena_state_'.$room->code));
        $this->assertSame($state, ArenaStateStore::get($room->code));
        $state['grid'][1][2] = 0;
        ArenaStateStore::cache()->put('arena_state_'.$room->code, $state, 600);
        $service->updateOrderedInput($room->code, 'host_player', $state['round_id'], [$this->frame(1, $state['started_at'])]);
        $this->travel(50)->milliseconds();
        $service->tickRoom($room->code);
        $this->getJson('/api/rooms/'.$room->code.'/state')->assertOk()->assertJsonPath('game_state.input_ack.host_player', 1)->assertJsonMissingPath('game_state.ordered_inputs');
        Cache::put('arena_state_OLD123', $state, 600);
        $this->assertSame($state, ArenaStateStore::get('OLD123'));
        $this->assertNull(ArenaStateStore::get('OTHER1'));
    }

    public function test_snapshot_publisher_coalesces_duplicates_reads_latest_state_and_rejects_previous_rounds(): void
    {
        Event::fake([GameRoomEvent::class]);
        $state = $this->state(2);
        $state['version'] = 10;
        $state['ordered_inputs'] = ['p0' => [$this->frame(1, $state['started_at'])]];
        ArenaStateStore::cache()->put('arena_state_PUB123', $state, 600);
        $publisher = new ArenaSnapshotPublisher;
        $round = $state['round_id'];
        $publisher->publish('PUB123', $round);
        $publisher->publish('PUB123', $round);
        Event::assertDispatchedTimes(GameRoomEvent::class, 1);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->data['version'] === 10 && ! isset($event->data['state']['ordered_inputs']));
        $state['version'] = 11;
        $this->travel(150)->milliseconds();
        ArenaStateStore::cache()->put('arena_state_PUB123', $state, 600);
        $publisher->publish('PUB123', $round);
        Event::assertDispatchedTimes(GameRoomEvent::class, 2);
        $state['round_id'] = 'new-round';
        ArenaStateStore::cache()->put('arena_state_PUB123', $state, 600);
        $publisher->publish('PUB123', $round);
        Event::assertDispatchedTimes(GameRoomEvent::class, 2);
    }

    public function test_signed_websocket_frames_are_validated_and_duplicate_messages_do_not_duplicate_commands(): void
    {
        $this->freezeTime();
        $time = (float) now()->format('U.u');
        $input = ['room' => 'WS1234', 'player' => 'host_player', 'round_id' => str_repeat('a', 32), 'sequence' => 1, 'frames' => [$this->frame(1, $time)], 'token' => ArenaInputService::token('WS1234', 'host_player')];
        $transport = app(ArenaInputService::class);
        $this->assertTrue($transport->store($input));
        $this->assertTrue($transport->store($input));
        $this->assertCount(1, OrderedInputBuffer::read('WS1234', $input['round_id'], 'host_player'));
        $this->assertFalse($transport->store([...$input, 'token' => 'invalid']));
        $this->assertFalse($transport->store([...$input, 'frames' => [[...$this->frame(2, $time), 'duration' => NAN]]]));
    }

    public function test_hot_path_processes_four_actors_without_losing_frames(): void
    {
        $fileStore = getenv('BOMBER_SYNC_IO_METRICS') === '1';
        config(['cache.arena_input_store' => $fileStore ? 'file' : 'array', 'cache.arena_state_store' => $fileStore ? 'file' : 'array']);
        Event::fake([GameRoomEvent::class]);
        $this->freezeTime();
        $service = app(RoomService::class);
        $room = $service->createRoom('host_player', 'Host');
        foreach (['guest_one', 'guest_two', 'guest_three'] as $id) {
            $service->joinRoom($room->code, $id, $id);
        }
        $state = $service->startGame($room->code, 'host_player')['state'];
        $state['grid'] = array_fill(0, 20, array_fill(0, 100, 0));
        foreach ($state['players'] as &$player) {
            $player['x'] = 1;
        }
        unset($player);
        ArenaStateStore::cache()->put('arena_state_'.$room->code, $state, 600);
        $inputTimes = [];
        $tickTimes = [];
        try {
            for ($tick = 1; $tick <= 60; $tick++) {
                $this->travel(50)->milliseconds();
                $time = (float) now()->format('U.u');
                foreach (array_keys($state['players']) as $id) {
                    $started = hrtime(true);
                    $this->assertTrue(OrderedInputBuffer::put($room->code, $state['round_id'], $id, [$this->frame($tick, $time)], singleWriter: true));
                    $inputTimes[] = (hrtime(true) - $started) / 1_000_000;
                }
                $started = hrtime(true);
                $response = $service->tickRoom($room->code);
                $tickTimes[] = (hrtime(true) - $started) / 1_000_000;
                foreach ($response['state']['input_ack'] as $ack) {
                    $this->assertSame($tick, $ack);
                }
            }
            if ($fileStore) {
                file_put_contents(base_path('BomberArena-synchronization-io.json'), json_encode(['input_ms' => $inputTimes, 'tick_ms' => $tickTimes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
        } finally {
            ArenaStateStore::cache()->forget('arena_state_'.$room->code);
            foreach (array_keys($state['players']) as $id) {
                Cache::store(config('cache.arena_input_store'))->forget(OrderedInputBuffer::key($room->code, $state['round_id'], $id));
            }
        }
    }

    public function test_presence_heartbeat_does_not_wait_for_or_take_the_simulation_lock(): void
    {
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('host_player', 'Host');
        $service->joinRoom($room->code, 'guest_player', 'Guest');
        $service->startGame($room->code, 'host_player');
        $lock = ArenaStateStore::cache()->lock('arena_lock_'.$room->code, 10);
        $this->assertTrue($lock->get());
        try {
            $result = app(RoomSocialService::class)->heartbeat($room->code, 'host_player');
            $this->assertTrue($result['success']);
            $this->assertFalse(app(RoomSocialService::class)->heartbeat($room->code, 'unknown_player')['success']);
        } finally {
            $lock->release();
        }
    }

    private function frame(int $sequence, float $time): array
    {
        return ['sequence' => $sequence, 'duration' => 0.05, 'direction' => 'right', 'sampled_at' => $time];
    }

    private function state(int $players): array
    {
        $records = [];
        for ($id = 0; $id < max(2, $players); $id++) {
            $records[] = ['player_id' => 'p'.$id, 'slot_index' => $id];
        }
        $state = (new BomberGameEngine)->createInitialState($records, 42424);
        $state['grid'] = array_fill(0, 20, array_fill(0, 100, 0));
        foreach ($state['players'] as $id => &$player) {
            $player['x'] = 1;
            $player['y'] = 1 + 2 * (int) substr($id, 1);
        }
        unset($player);

        return $state;
    }
}
