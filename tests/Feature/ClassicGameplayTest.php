<?php

namespace Tests\Feature;

use App\Events\GameRoomEvent;
use App\Models\GameRule;
use App\Models\User;
use App\Services\ArenaInputService;
use App\Services\BomberGameEngine;
use App\Services\ClassicMovement;
use App\Services\GameRulesService;
use App\Services\RoomService;
use App\Services\RoomSocialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClassicGameplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_continuous_motion_has_constant_speed_and_stops_without_replaying_old_inputs(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        [$service, $code, $round] = $this->room();
        $sequence = 0;
        for ($tick = 0; $tick <= 20; $tick++) {
            if ($tick % 3 === 0) {
                $service->updateInput($code, 'host_player', $round, ++$sequence, 'right', (float) now()->format('U.u'));
            }
            if ($tick < 20) {
                $this->travel(50)->milliseconds();
                $service->tickRoom($code);
            }
        }
        $state = Cache::get('arena_state_'.$code);
        $this->assertEqualsWithDelta(5, $state['players']['host_player']['position_x'], 0.00001);
        $service->updateInput($code, 'host_player', $round, ++$sequence, null, (float) now()->format('U.u'));
        $stopped = Cache::get('arena_state_'.$code)['players']['host_player'];

        $this->travel(3)->seconds();
        $state = $service->tickRoom($code)['state'];

        $this->assertSame($stopped['position_x'], $state['players']['host_player']['position_x']);
        $this->assertSame([], $state['pending_moves']);
        Event::assertDispatched(GameRoomEvent::class);
    }

    #[TestWith([30])]
    #[TestWith([100])]
    #[TestWith([200])]
    #[TestWith([300])]
    public function test_delayed_current_inputs_expire_and_a_paused_tick_cannot_replay_a_burst(int $latency): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $this->state();
        $time = $state['started_at'];
        $state['current_inputs']['p0'] = ['direction' => 'right', 'sequence' => 1, 'received_at' => $time, 'expires_at' => $time + 0.5 - $latency / 1000];

        $engine->advanceMovement($state, $time + 0.1);
        $before = $state['players']['p0']['position_x'];
        $engine->advanceMovement($state, $time + 3);

        $this->assertSame($before, $state['players']['p0']['position_x']);
        $state['current_inputs']['p0'] = ['direction' => 'right', 'sequence' => 2, 'received_at' => $time + 3, 'expires_at' => $time + 4];
        $engine->advanceMovement($state, $time + 3.5);
        $this->assertLessThanOrEqual(0.400001, $state['players']['p0']['position_x'] - $before);
    }

    public function test_frontally_crossing_players_share_space_and_swap_without_blocking(): void
    {
        $state = $this->state();
        $state['players']['p1']['x'] = 3;
        $state['players']['p1']['y'] = 1;
        for ($tick = 0; $tick < 10; $tick++) {
            $state['players']['p0'] = ClassicMovement::step($state, $state['players']['p0'], 'right', 0.05);
            $state['players']['p1'] = ClassicMovement::step($state, $state['players']['p1'], 'left', 0.05);
        }

        $this->assertEqualsWithDelta(3, $state['players']['p0']['position_x'], 0.00001);
        $this->assertEqualsWithDelta(1, $state['players']['p1']['position_x'], 0.00001);
    }

    #[TestWith([[[1, 2], [3, 2]], ['right', 'left'], [[2, 2], [2, 2]]], 'J01')]
    #[TestWith([[[1, 2], [2, 1]], ['right', 'down'], [[2, 2], [2, 2]]], 'J02')]
    #[TestWith([[[1, 2], [2, 2]], ['right', 'left'], [[2, 2], [1, 2]]], 'J03')]
    #[TestWith([[[2, 3], [4, 3], [3, 2], [3, 4]], ['right', 'left', 'down', 'up'], [[3, 3], [3, 3], [3, 3], [3, 3]]], 'J04')]
    #[TestWith([[[1, 2], [2, 2]], ['right', 'right'], [[2, 2], [3, 2]]], 'J10')]
    #[TestWith([[[1, 2], [3, 2], [1, 4], [3, 4]], ['right', 'left', 'right', 'left'], [[2, 2], [2, 2], [2, 4], [2, 4]]], 'J14')]
    #[TestWith([[[1, 2], [2, 2]], ['right', null], [[2, 2], [2, 2]]], 'J15')]
    public function test_classic_players_can_cross_and_share_space_independently_of_iteration_order(array $positions, array $directions, array $expected): void
    {
        $players = [];
        foreach ($positions as $index => [$x, $y]) {
            $players[] = ['player_id' => 'p'.$index, 'slot_index' => $index];
        }
        $state = (new BomberGameEngine)->createInitialState($players, 42424);
        $state['grid'] = array_fill(0, 11, array_fill(0, 13, 0));
        foreach ($positions as $index => [$x, $y]) {
            $state['players']['p'.$index]['x'] = $x;
            $state['players']['p'.$index]['y'] = $y;
        }
        $reversed = $state;
        $reversed['players'] = array_reverse($reversed['players'], true);
        foreach (range(1, 5) as $tick) {
            foreach ([&$state, &$reversed] as &$simulation) {
                foreach ($simulation['players'] as $id => $player) {
                    $simulation['players'][$id] = ClassicMovement::step($simulation, $player, $directions[$player['slot']], 0.05);
                }
            }
            unset($simulation);
        }
        foreach ($expected as $index => [$x, $y]) {
            $actual = $state['players']['p'.$index];
            $this->assertEqualsWithDelta($x, $actual['position_x'] ?? $actual['x'], 0.00001);
            $this->assertEqualsWithDelta($y, $actual['position_y'] ?? $actual['y'], 0.00001);
            $this->assertSame($actual, $reversed['players']['p'.$index]);
        }
    }

    public function test_walls_bombs_and_exit_permission_apply_to_continuous_hitboxes(): void
    {
        $this->freezeTime();
        $state = $this->state();
        $engine = new BomberGameEngine;
        $bomb = $engine->handlePlaceBomb($state, 'p0')['bomb']['id'];
        for ($tick = 0; $tick < 5; $tick++) {
            $state['players']['p0'] = ClassicMovement::step($state, $state['players']['p0'], 'right', 0.05);
        }
        $this->assertEqualsWithDelta(2, $state['players']['p0']['position_x'], 0.00001);
        $state['bombs'][$bomb]['pass_through_players'] = [];
        for ($tick = 0; $tick < 10; $tick++) {
            $state['players']['p0'] = ClassicMovement::step($state, $state['players']['p0'], 'left', 0.05);
        }
        $this->assertGreaterThanOrEqual(1.78, $state['players']['p0']['position_x']);
        $state['grid'][1][3] = 1;
        for ($tick = 0; $tick < 10; $tick++) {
            $state['players']['p0'] = ClassicMovement::step($state, $state['players']['p0'], 'right', 0.05);
        }
        $this->assertLessThanOrEqual(2.220001, $state['players']['p0']['position_x']);
    }

    public function test_corner_assistance_centers_in_small_axis_steps_without_teleporting(): void
    {
        $state = $this->state();
        $player = $state['players']['p0'];
        $player['position_x'] = 1.15;
        $player['position_y'] = 1.0;

        $next = ClassicMovement::step($state, $player, 'down', 0.016);

        $this->assertEqualsWithDelta(1.086, $next['position_x'], 0.00001);
        $this->assertSame(1.0, $next['position_y']);
        $this->assertSame(1.15, $player['position_x']);
    }

    public function test_input_sequences_prevent_late_start_from_overwriting_stop(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        [$service, $code, $round] = $this->room();
        $time = (float) now()->format('U.u');
        $this->withHeader('X-Player-ID', 'host_player')->postJson('/api/rooms/'.$code.'/input', ['round_id' => $round, 'sequence' => 2, 'direction' => null, 'sampled_at' => $time])->assertOk();
        $this->postJson('/api/rooms/'.$code.'/input', ['round_id' => $round, 'sequence' => 1, 'direction' => 'right', 'sampled_at' => $time])->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson('/api/rooms/'.$code.'/input', ['round_id' => $round, 'sequence' => 3, 'direction' => 'right', 'sampled_at' => $time - 1])->assertUnprocessable()->assertJsonPath('stale_input', true);

        $this->assertNull(Cache::get('arena_state_'.$code)['current_inputs']['host_player']['direction']);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'game_started');
    }

    public function test_classic_defaults_and_technical_constraints_only_change_new_rounds(): void
    {
        Event::fake([GameRoomEvent::class]);
        [$service, $code, $round] = $this->room();
        $before = Cache::get('arena_state_'.$code);
        $rules = app(GameRulesService::class);
        $saved = $rules->saveNewRules(['player_collisions' => true, 'movement_speed' => 30, 'simulation_hz' => 30, 'publish_hz' => 80]);

        $this->assertSame(false, $before['rules']['player_collisions']);
        $this->assertSame('draw', $before['rules']['timeout_result']);
        $this->assertSame(6, $saved->settings['movement_speed']);
        $this->assertSame(30, $saved->settings['publish_hz']);
        $this->assertSame($before, Cache::get('arena_state_'.$code));
        Event::assertDispatched(GameRoomEvent::class);
    }

    public function test_draw_can_be_followed_by_a_rematch_requested_by_a_participant(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        [$service, $code, $round] = $this->room();
        $this->travel(181)->seconds();
        $result = $service->tickRoom($code);
        $this->assertNull($result['state']['winner_id']);

        $social = app(RoomSocialService::class);
        $social->heartbeat($code, 'host_player');
        $social->heartbeat($code, 'guest_player');
        $vote = $service->requestRematch($code, 'host_player', $round);
        $this->assertTrue($vote['success']);
        $rematch = $social->respond($code, 'guest_player', $vote['rematch']['id'], $round, true);

        $this->assertTrue($rematch['success']);
        $this->assertNotSame($round, $rematch['state']['round_id']);
        $this->assertFalse($rematch['state']['rules']['player_collisions']);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'game_started');
    }

    public function test_signed_websocket_input_is_consumed_by_the_worker_and_cannot_be_forged_or_replayed(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        [$service, $code, $round] = $this->room();
        $transport = app(ArenaInputService::class);
        $input = ['room' => $code, 'player' => 'host_player', 'round_id' => $round, 'sequence' => 1, 'direction' => 'right', 'sampled_at' => (float) now()->format('U.u'), 'token' => ArenaInputService::token($code, 'host_player')];

        $this->assertFalse($transport->store([...$input, 'token' => str_repeat('0', 64)]));
        $this->assertTrue($transport->store($input));
        $this->assertFalse($transport->store($input));
        $service->tickRoom($code);
        $this->travel(100)->milliseconds();
        $state = $service->tickRoom($code)['state'];

        $this->assertEqualsWithDelta(1.4, $state['players']['host_player']['position_x'], 0.000001);
        $this->assertSame(1, $state['input_ack']['host_player']);
        $this->assertTrue($transport->store([...$input, 'sequence' => 2, 'direction' => null]));
        $this->travel(50)->milliseconds();
        $stopped = $service->tickRoom($code)['state'];
        $this->assertSame($state['players']['host_player']['position_x'], $stopped['players']['host_player']['position_x']);
        Event::assertDispatched(GameRoomEvent::class);
    }

    public function test_classic_simulation_profile_with_two_three_four_players_and_ten_rooms(): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $metrics = [];
        foreach ([2, 3, 4, 40] as $players) {
            foreach ([30, 100, 200, 300] as $latency) {
                $roomCount = $players === 40 ? 10 : 1;
                $records = [];
                for ($i = 0; $i < min(4, $players); $i++) {
                    $records[] = ['player_id' => 'p'.$i, 'slot_index' => $i];
                }
                $initial = $engine->createInitialState($records, 42424);
                $states = array_fill(0, $roomCount, $initial);
                $base = $initial['started_at'];
                $samples = [];
                for ($tick = 1; $tick <= 200; $tick++) {
                    $time = $base + $tick * 0.05;
                    foreach ($states as &$state) {
                        foreach ($state['players'] as $id => $player) {
                            if ($tick % 3 === 1 && ! ($tick >= 70 && $tick <= 90)) {
                                $direction = (intdiv($tick, 8) % 2 === $player['slot'] % 2) ? 'right' : 'left';
                                $state['current_inputs'][$id] = ['sequence' => $tick, 'direction' => $direction, 'received_at' => $time, 'expires_at' => $time + 0.5 - $latency / 1000 - ($tick % 3) * 0.005];
                            }
                        }
                        $started = hrtime(true);
                        $engine->advanceMovement($state, $time);
                        $samples[] = (hrtime(true) - $started) / 1_000_000;
                        foreach ($state['players'] as $player) {
                            $this->assertTrue(ClassicMovement::isFree($state, $player, (float) ($player['position_x'] ?? $player['x']), (float) ($player['position_y'] ?? $player['y'])));
                        }
                    }
                    unset($state);
                }
                sort($samples);
                $metrics[] = ['players' => $players, 'rooms' => $roomCount, 'virtual_one_way_ms' => $latency, 'simulation_hz' => 20, 'simulated_seconds' => 10, 'mean_engine_ms' => array_sum($samples) / count($samples), 'p95_engine_ms' => $samples[(int) floor(count($samples) * 0.95) - 1], 'max_engine_ms' => max($samples), 'process_peak_memory_bytes' => memory_get_peak_usage(true), 'pending_steps' => array_sum(array_map(fn (array $state): int => count($state['pending_moves']), $states))];
            }
        }
        if (getenv('BOMBER_RECOVERY_METRICS') === '1') {
            file_put_contents(base_path('BomberArena-recovery-metrics.json'), json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->assertCount(16, $metrics);
    }

    public function test_stop_ack_is_published_even_when_no_further_movement_changes_the_state(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        [$service, $code, $round] = $this->room();
        $state = Cache::get('arena_state_'.$code);
        $state['last_published_at'] = (float) now()->format('U.u');
        Cache::put('arena_state_'.$code, $state, 600);
        $service->updateInput($code, 'host_player', $round, 1, null, (float) now()->format('U.u'));
        $this->travel(120)->milliseconds();

        $result = $service->tickRoom($code);

        $snapshots = array_values(array_filter($result['events'], fn (array $event): bool => $event['type'] === 'state_updated'));
        $this->assertCount(1, $snapshots);
        $this->assertSame(1, $snapshots[0]['data']['state']['input_ack']['host_player']);
    }

    public function test_refreshing_a_held_websocket_input_does_not_restart_its_simulation_window(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        [$service, $code, $round] = $this->room();
        $transport = app(ArenaInputService::class);
        $input = ['room' => $code, 'player' => 'host_player', 'round_id' => $round, 'sequence' => 1, 'direction' => 'right', 'sampled_at' => (float) now()->format('U.u'), 'token' => ArenaInputService::token($code, 'host_player')];
        $this->assertTrue($transport->store($input));
        $service->tickRoom($code);
        $received = Cache::get('arena_state_'.$code)['current_inputs']['host_player']['received_at'];
        $this->travel(150)->milliseconds();
        $this->assertTrue($transport->store([...$input, 'sequence' => 2, 'sampled_at' => (float) now()->format('U.u')]));

        $state = $service->tickRoom($code)['state'];

        $this->assertSame($received, $state['current_inputs']['host_player']['received_at']);
        $this->assertEqualsWithDelta((float) now()->format('U.u'), $state['players']['host_player']['last_seen_at'], 0.000001);
        $this->assertGreaterThan(1.2, $state['players']['host_player']['position_x']);
    }

    #[TestWith([20])]
    #[TestWith([25])]
    #[TestWith([30])]
    public function test_configured_simulation_rates_preserve_one_second_of_constant_speed(int $frequency): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $this->state();
        $state['rules']['simulation_hz'] = $frequency;
        $time = $state['started_at'];
        $state['current_inputs']['p0'] = ['direction' => 'right', 'sequence' => 1, 'received_at' => $time, 'expires_at' => $time + 2];
        for ($step = 1; $step <= $frequency; $step++) {
            $engine->advanceMovement($state, $time + $step / $frequency);
        }
        $this->assertEqualsWithDelta(5, $state['players']['p0']['position_x'], 0.00005);
    }

    public function test_only_administrators_can_save_compatible_technical_settings(): void
    {
        $settings = [...GameRule::defaultSettings(), 'simulation_hz' => 20, 'publish_hz' => 30];
        $this->postJson(route('admin.rules.update'), $settings)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson(route('admin.rules.update'), $settings)->assertUnprocessable()->assertJsonValidationErrors('publish_hz');
        $this->postJson(route('admin.rules.update'), [...$settings, 'publish_hz' => 10])->assertOk()->assertJsonPath('settings.player_collisions', false);
        $this->get(route('admin.rules'))->assertOk()->assertSee('simulation_hz', false);
    }

    private function state(): array
    {
        $state = (new BomberGameEngine)->createInitialState([['player_id' => 'p0', 'slot_index' => 0], ['player_id' => 'p1', 'slot_index' => 1]], 42424);
        $state['grid'] = array_fill(0, 11, array_fill(0, 13, 0));

        return $state;
    }

    /** @return array{0: RoomService, 1: string, 2: string} */
    private function room(): array
    {
        $service = app(RoomService::class);
        $room = $service->createRoom('host_player', 'Host');
        $service->joinRoom($room->code, 'guest_player', 'Guest');
        $state = $service->startGame($room->code, 'host_player')['state'];
        for ($x = 1; $x <= 11; $x++) {
            $state['grid'][1][$x] = 0;
        }
        Cache::put('arena_state_'.$room->code, $state, 600);

        return [$service, $room->code, $state['round_id']];
    }
}
