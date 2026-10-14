<?php

namespace Tests\Feature;

use App\Events\GameRoomEvent;
use App\Models\User;
use App\Services\BomberGameEngine;
use App\Services\RoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MultiplayerAuditTest extends TestCase
{
    use RefreshDatabase;

    public static function movementCases(): array
    {
        return [
            'J01 frontal' => [[[1, 1], [3, 1]], ['right', 'left'], [false, false]],
            'J02 perpendicular' => [[[1, 1], [2, 2]], ['right', 'up'], [false, false]],
            'J03 intercambio' => [[[1, 1], [2, 1]], ['right', 'left'], [false, false]],
            'J04 congestion' => [[[2, 3], [4, 3], [3, 2], [3, 4]], ['right', 'left', 'down', 'up'], [false, false, false, false]],
            'J05 muro' => [[[1, 1]], ['up'], [false]],
            'J06 bloque' => [[[3, 3]], ['right'], [false], [4, 3, 2]],
            'J07 borde' => [[[0, 1]], ['left'], [false]],
            'J08 libre' => [[[1, 1]], ['right'], [true]],
            'J09 muerto' => [[[1, 1], [2, 1]], ['right'], [true], null, 1],
            'J10 no atravesar origen' => [[[1, 1], [2, 1]], ['right', 'right'], [false, true]],
            'J11 independientes' => [[[1, 1], [11, 9], [11, 1], [1, 9]], ['right', 'left', 'left', 'right'], [true, true, true, true]],
            'J12 invalida' => [[[1, 1]], ['diagonal'], [false]],
            'J13 eliminado' => [[[1, 1]], ['right'], [false], null, 0],
            'J14 dos disputas' => [[[1, 1], [3, 1], [1, 3], [3, 3]], ['right', 'left', 'right', 'left'], [false, false, false, false]],
            'J15 obligatorio' => [[[1, 1], [2, 1]], ['right'], [false]],
        ];
    }

    #[DataProvider('movementCases')]
    public function test_occupancy_is_independent_of_arrival_order(array $positions, array $directions, array $expected, ?array $solid = null, ?int $dead = null): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $this->state(count($positions));
        $state['rules']['player_collisions'] = false;
        $state['rules']['dead_players_block_movement'] = true;
        foreach ($positions as $i => [$x, $y]) {
            $state['players']['p'.$i]['x'] = $x;
            $state['players']['p'.$i]['y'] = $y;
        }
        if ($solid) {
            $state['grid'][$solid[1]][$solid[0]] = $solid[2];
        }
        if ($dead !== null) {
            $state['players']['p'.$dead]['alive'] = false;
        }
        $commands = [];
        foreach ($directions as $i => $direction) {
            $commands['p'.$i] = $direction;
        }
        $reversed = $state;

        $result = $engine->resolveMovementTick($state, $commands, $state['started_at'] + 1);
        $reverseResult = $engine->resolveMovementTick($reversed, array_reverse($commands, true), $state['started_at'] + 1);

        $this->assertSame($result, $reverseResult);
        $this->assertSame($state, $reversed);
        $this->assertSame($expected, array_column($result, 'success'));
    }

    public static function bombCases(): array
    {
        $cases = [];
        foreach (range(1, 15) as $i) {
            $cases[sprintf('B%02d', $i)] = [$i];
        }

        return $cases;
    }

    #[DataProvider('bombCases')]
    public function test_bomb_boundaries(int $case): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $this->state(4);
        $time = $state['started_at'];
        $state['players']['p0']['x'] = 3;
        $state['players']['p0']['y'] = 3;
        $placed = $engine->handlePlaceBomb($state, 'p0');
        $id = $placed['bomb']['id'];

        if ($case === 1 || $case === 2) {
            $state['players']['p0']['max_bombs'] = $case;
            $this->assertFalse($engine->handlePlaceBomb($state, 'p0')['success']);
        } elseif ($case === 3) {
            $this->assertTrue($engine->handleMove($state, 'p0', 'right')['success']);
        } elseif ($case === 4) {
            $engine->handleMove($state, 'p0', 'right');
            $this->assertFalse($engine->handleMove($state, 'p0', 'left')['success']);
        } elseif ($case === 5) {
            $this->assertSame([], $engine->checkAndDetonateExpiredBombs($state, $time + 1.999));
        } elseif ($case === 6) {
            $this->assertCount(1, $engine->checkAndDetonateExpiredBombs($state, $time + 2));
        } elseif ($case === 7) {
            $engine->detonateBomb($state, $id);
            $this->assertFalse($engine->detonateBomb($state, $id)['success']);
        } elseif ($case === 8 || $case === 9) {
            $state['players']['p1']['x'] = 4;
            $state['players']['p1']['y'] = 3;
            $engine->handlePlaceBomb($state, 'p1');
            $state['rules']['allow_chain_reaction'] = $case === 8;
            $result = $engine->detonateBomb($state, $id);
            $this->assertCount($case === 8 ? 2 : 1, $result['detonated_bomb_ids']);
        } elseif ($case === 10 || $case === 11) {
            $state['grid'][3][4] = $case === 10 ? 1 : 2;
            $result = $engine->detonateBomb($state, $id);
            $this->assertNotContains(['x' => 5, 'y' => 3], $result['explosion_tiles']);
            $this->assertSame($case === 10 ? 1 : 0, $state['grid'][3][4]);
        } elseif ($case === 12 || $case === 13) {
            $state['rules']['self_damage'] = $case === 12;
            $engine->detonateBomb($state, $id);
            $this->assertSame($case === 13, $state['players']['p0']['alive']);
        } else {
            $state['rules']['friendly_fire'] = $case === 15;
            $state['players']['p1']['x'] = 4;
            $state['players']['p1']['y'] = 3;
            $engine->detonateBomb($state, $id);
            $this->assertSame($case === 14, $state['players']['p1']['alive']);
            $this->assertSame($case === 15 ? 1 : 0, $state['players']['p0']['kills']);
        }
    }

    public static function networkCases(): array
    {
        $cases = [];
        foreach ([30, 150, 300] as $latency) {
            foreach ([2, 3, 4, 8] as $players) {
                $cases[sprintf('L%02d', count($cases) + 1)] = [$latency, $players];
            }
        }

        return $cases;
    }

    #[DataProvider('networkCases')]
    public function test_delays_and_jitter_never_overlap_or_replay_after_disconnect(int $latency, int $players): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $rooms = array_fill(0, $players === 8 ? 2 : 1, $this->state(min(4, $players)));
        foreach ($rooms as &$state) {
            $base = $state['started_at'];
            for ($frame = 1; $frame <= 40; $frame++) {
                $time = $base + $frame * 0.1 + $latency / 1000 + ($frame % 3) * 0.007;
                $tick = (int) floor(($time - $base) / BomberGameEngine::TICK_SECONDS) + 1;
                foreach (array_keys($state['players']) as $i => $id) {
                    $state['pending_moves'][$tick][$id] = $frame % 2 ? ($i % 2 ? 'left' : 'right') : ($i % 2 ? 'right' : 'left');
                }
                $engine->advanceMovement($state, $time + 0.06);
                $positions = array_map(fn (array $p): string => $p['x'].'_'.$p['y'], $state['players']);
                $this->assertCount(count($positions), array_unique($positions));
            }
            $before = $state['players'];
            $state['pending_moves'][$state['tick'] + 1]['p0'] = 'right';
            $engine->advanceMovement($state, $base + 20);
            $this->assertSame($before, $state['players']);
            $this->assertSame([], $state['pending_moves']);
        }
    }

    public static function outcomeCases(): array
    {
        return [
            'E01 cuatro' => [4, 4, false, null], 'E02 tres' => [4, 3, false, null],
            'E03 dos' => [4, 2, false, null], 'E04 ultimo' => [4, 1, true, 'p0'],
            'E05 ninguno' => [4, 0, true, null], 'E06 dos iniciales' => [2, 1, true, 'p0'],
            'E07 empate tiempo' => [4, 4, true, null, true], 'E08 bombas' => [4, 4, true, 'p0', true, 'most_bombs', 3],
            'E09 kills' => [4, 4, true, 'p0', true, 'most_kills', 3], 'E10 pendiente' => [2, 2, false, null],
            'E11 ultimo desactivado' => [4, 1, false, null, false, 'most_bombs', 0, false], 'E12 timeout sin vivos' => [4, 0, true, null, true],
        ];
    }

    #[DataProvider('outcomeCases')]
    public function test_match_completion(int $initial, int $alive, bool $over, ?string $winner, bool $timeout = false, string $policy = 'most_bombs', int $score = 0, bool $lastSurvivor = true): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $this->state($initial);
        foreach ($state['players'] as $id => &$player) {
            $player['alive'] = (int) substr($id, 1) < $alive;
        }
        unset($player);
        $state['rules']['end_on_last_survivor'] = $lastSurvivor;
        $state['rules']['timeout_result'] = $policy;
        $state['players']['p0'][$policy === 'most_kills' ? 'kills' : 'bombs_placed'] = $score;

        $engine->evaluateMatchOutcome($state, $state['started_at'] + ($timeout ? 180 : 10));

        $this->assertSame($over, $state['is_game_over']);
        $this->assertSame($winner, $state['winner_id']);
        if ($over) {
            $final = $state;
            $this->assertFalse($engine->handleMove($state, 'p0', 'right')['success']);
            $this->assertFalse($engine->handlePlaceBomb($state, 'p0')['success']);
            $this->assertSame($final, $state);
        }
    }

    public function test_duplicate_sequences_do_not_repeat_bombs_and_rooms_are_isolated(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('p0', 'Host');
        $service->joinRoom($room->code, 'p1', 'Guest');
        $state = $service->startGame($room->code, 'p0')['state'];
        $other = $service->createRoom('p0', 'Host');
        $service->joinRoom($other->code, 'p1', 'Guest');
        $otherState = $service->startGame($other->code, 'p0')['state'];

        $first = $service->handleActions($room->code, 'p0', [['type' => 'bomb']], $state['round_id'], 1);
        $second = $service->handleActions($room->code, 'p0', [['type' => 'bomb']], $state['round_id'], 1);

        $this->assertSame(1, $first['state']['players']['p0']['bombs_placed']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, $second['state']['players']['p0']['bombs_placed']);
        $this->assertSame($otherState, Cache::get('arena_state_'.$other->code));
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'bomb_placed' && $event->roomCode === $room->code);
    }

    public function test_expired_bombs_kill_before_new_movement_can_escape(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('p0', 'Host');
        $service->joinRoom($room->code, 'p1', 'Guest');
        $state = $service->startGame($room->code, 'p0')['state'];
        $service->placeBomb($room->code, 'p0');
        $this->travel(2100)->milliseconds();

        $result = $service->handleActions($room->code, 'p0', [['type' => 'move', 'direction' => 'right']], $state['round_id'], 1);

        $this->assertFalse($result['state']['players']['p0']['alive']);
        $this->assertSame(1, $result['state']['players']['p0']['x']);
        $this->assertTrue($result['state']['is_game_over']);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'bomb_exploded');
    }

    public function test_simulation_cost_for_players_rooms_latency_and_jitter(): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $measurements = [];
        foreach ([2, 3, 4, 40] as $playerCount) {
            foreach ([30, 150, 300] as $latency) {
                $roomCount = $playerCount === 40 ? 10 : 1;
                $states = array_fill(0, $roomCount, $this->state(min(4, $playerCount)));
                $base = $states[0]['started_at'];
                $samples = [];
                $events = 0;
                $pendingNetwork = [];
                $cpuStarted = hrtime(true);
                for ($tick = 1; $tick <= 1200; $tick++) {
                    $time = $base + $tick * BomberGameEngine::TICK_SECONDS;
                    if ($tick % 4 === 0 && ! ($tick >= 400 && $tick <= 460)) {
                        foreach ($states as $roomIndex => $state) {
                            foreach (array_keys($state['players']) as $i => $id) {
                                $delay = $latency / 1000 * ($i + 1) / 4 + (($tick * 17 + $i * 31) % 40) / 1000;
                                $pendingNetwork[] = ['delivery' => $time + $delay, 'room' => $roomIndex, 'id' => $id, 'direction' => ($tick / 4) % 2 ? ($i % 2 ? 'left' : 'right') : ($i % 2 ? 'right' : 'left')];
                            }
                        }
                    }
                    foreach ($pendingNetwork as $key => $command) {
                        if ($command['delivery'] <= $time) {
                            $states[$command['room']]['pending_moves'][$tick + 1][$command['id']] = $command['direction'];
                            unset($pendingNetwork[$key]);
                        }
                    }
                    $started = hrtime(true);
                    foreach ($states as &$state) {
                        $events += count($engine->advanceMovement($state, $time));
                        $positions = array_map(fn (array $p): string => $p['x'].'_'.$p['y'], $state['players']);
                        $this->assertCount(count($positions), array_unique($positions));
                    }
                    unset($state);
                    $samples[] = (hrtime(true) - $started) / 1_000_000;
                }
                sort($samples);
                $measurements[] = [
                    'players' => $playerCount, 'rooms' => $roomCount, 'virtual_latency_ms' => $latency,
                    'simulated_seconds' => 60, 'ticks' => 1200,
                    'mean_tick_ms' => array_sum($samples) / count($samples),
                    'p95_tick_ms' => $samples[1139], 'max_tick_ms' => max($samples),
                    'wall_duration_ms' => (hrtime(true) - $cpuStarted) / 1_000_000,
                    'engine_events' => $events,
                    'rejected_commands' => array_sum(array_map(fn (array $s): int => $s['diagnostics']['rejected_moves'], $states)),
                    'rejected_collisions' => array_sum(array_map(fn (array $s): int => $s['diagnostics']['rejected_collisions'], $states)),
                    'process_peak_memory_bytes' => memory_get_peak_usage(true),
                ];
            }
        }
        if (getenv('BOMBER_AUDIT_METRICS') === '1') {
            file_put_contents(base_path('BomberArena-audit-metrics.json'), json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->assertCount(12, $measurements);
    }

    public function test_speed_and_duplicate_moves_cannot_bypass_server_cooldown(): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $this->state(2);
        $time = $state['started_at'];

        $this->assertTrue($engine->resolveMovementTick($state, ['p0' => 'right'], $time)['p0']['success']);
        $this->assertFalse($engine->resolveMovementTick($state, ['p0' => 'right'], $time + 0.05)['p0']['success']);
        $this->assertSame(2, $state['players']['p0']['x']);
        $this->assertTrue($engine->resolveMovementTick($state, ['p0' => 'right'], $time + 0.151)['p0']['success']);
    }

    public function test_console_tick_finishes_rounds_without_connected_clients(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('p0', 'Host');
        $service->joinRoom($room->code, 'p1', 'Guest');
        $service->startGame($room->code, 'p0');
        $this->travel(181)->seconds();

        $this->artisan('arena:tick', ['--once' => true])->assertSuccessful();

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => 'finished', 'finish_reason' => 'TIME_EXPIRED']);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'game_over');
    }

    public function test_chain_self_damage_uses_each_bomb_owner_instead_of_the_root_owner(): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $this->state(4);
        $state['rules']['self_damage'] = false;
        $state['players']['p0']['x'] = 3;
        $state['players']['p0']['y'] = 3;
        $state['players']['p1']['x'] = 5;
        $state['players']['p1']['y'] = 3;
        $root = $engine->handlePlaceBomb($state, 'p0')['bomb']['id'];
        $engine->handlePlaceBomb($state, 'p1');
        $state['players']['p1']['x'] = 7;

        $result = $engine->detonateBomb($state, $root);

        $this->assertTrue($state['players']['p1']['alive']);
        $this->assertCount(2, $result['detonated_bomb_ids']);
        $this->assertSame(0, $state['players']['p1']['active_bombs']);
    }

    public function test_sequence_validation_and_http_replays_preserve_authoritative_state(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('host_player', 'Host');
        $service->joinRoom($room->code, 'guest_player', 'Guest');
        $state = $service->startGame($room->code, 'host_player')['state'];
        $payload = ['round_id' => $state['round_id'], 'sequence' => 1, 'actions' => [['type' => 'bomb']]];

        $this->withHeader('X-Player-ID', 'host_player')->postJson('/api/rooms/'.$room->code.'/actions', [...$payload, 'sequence' => 0])->assertUnprocessable()->assertJsonValidationErrors('sequence');
        $this->assertSame($state, Cache::get('arena_state_'.$room->code));
        $this->postJson('/api/rooms/'.$room->code.'/actions', $payload)->assertOk()->assertJsonPath('state.players.host_player.bombs_placed', 1);
        $this->postJson('/api/rooms/'.$room->code.'/actions', $payload)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('state.players.host_player.bombs_placed', 1);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'bomb_placed');
    }

    public function test_higher_sequence_wins_within_the_same_tick_in_either_arrival_order(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        foreach ([[1, 2], [2, 1]] as $order) {
            $room = $service->createRoom('p0', 'Host');
            $service->joinRoom($room->code, 'p1', 'Guest');
            $state = $service->startGame($room->code, 'p0')['state'];
            foreach ($order as $sequence) {
                $service->handleActions($room->code, 'p0', [['type' => 'move', 'direction' => $sequence === 1 ? 'right' : 'down']], $state['round_id'], $sequence);
            }
            $this->travel(100)->milliseconds();
            $result = $service->tickRoom($room->code);
            $this->assertSame(1, $result['state']['players']['p0']['x']);
            $this->assertSame(2, $result['state']['players']['p0']['y']);
        }
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'player_moved');
    }

    public function test_disconnected_player_releases_occupancy_and_cannot_be_revived_by_heartbeat(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('p0', 'Host');
        foreach (['p1', 'p2', 'p3'] as $id) {
            $service->joinRoom($room->code, $id, $id);
        }
        $state = $service->startGame($room->code, 'p0')['state'];
        $state['players']['p1']['x'] = 2;
        $state['players']['p1']['y'] = 1;
        Cache::put('arena_state_'.$room->code, $state, 600);
        for ($second = 0; $second < 17; $second++) {
            foreach (['p0', 'p2', 'p3'] as $id) {
                $service->tickRoom($room->code, $state['round_id'], $id);
            }
            $this->travel(1)->seconds();
        }

        $result = $service->tickRoom($room->code, $state['round_id'], 'p1');

        $this->assertFalse($result['state']['players']['p1']['alive']);
        $this->assertTrue($result['state']['players']['p0']['alive']);
        $service->handleActions($room->code, 'p0', [['type' => 'move', 'direction' => 'right']], $state['round_id'], 1);
        $this->travel(100)->milliseconds();
        $result = $service->tickRoom($room->code, $state['round_id'], 'p0');
        $this->assertSame(2, $result['state']['players']['p0']['x']);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'player_disconnected' && $event->data['player_id'] === 'p1');
    }

    public function test_held_direction_moves_without_one_http_request_per_step_and_can_escape_a_bomb(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('host_player', 'Host');
        $service->joinRoom($room->code, 'guest_player', 'Guest');
        $state = $service->startGame($room->code, 'host_player')['state'];
        for ($x = 1; $x <= 11; $x++) {
            $state['grid'][1][$x] = 0;
        }
        Cache::put('arena_state_'.$room->code, $state, 600);
        $service->placeBomb($room->code, 'host_player');

        $this->withHeader('X-Player-ID', 'host_player')->postJson('/api/rooms/'.$room->code.'/actions', ['round_id' => $state['round_id'], 'sequence' => 1, 'actions' => [['type' => 'walk', 'direction' => 'right']]])->assertOk();
        for ($tick = 1; $tick <= 8; $tick++) {
            $this->travel(50)->milliseconds();
            $service->tickRoom($room->code);
        }
        $stateAfterWalk = Cache::get('arena_state_'.$room->code);
        $this->assertSame(5, $stateAfterWalk['players']['host_player']['x']);
        $this->postJson('/api/rooms/'.$room->code.'/actions', ['round_id' => $state['round_id'], 'sequence' => 2, 'actions' => [['type' => 'stop']]])->assertOk();
        $this->travel(1700)->milliseconds();
        $result = $service->tickRoom($room->code);

        $this->assertSame(5, $result['state']['players']['host_player']['x']);
        $this->assertTrue($result['state']['players']['host_player']['alive']);
        $this->assertSame([], $result['state']['bombs']);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'bomb_exploded');
    }

    public function test_expired_walk_intent_stops_and_two_held_players_cannot_share_a_destination(): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $this->state(2);
        $time = $state['started_at'];
        $state['players']['p1']['x'] = 3;
        $state['players']['p1']['y'] = 1;
        $state['movement_intents'] = [
            'p0' => ['direction' => 'right', 'started_at' => $time, 'expires_at' => $time + 1],
            'p1' => ['direction' => 'left', 'started_at' => $time, 'expires_at' => $time + 1],
        ];

        $engine->advanceMovement($state, $time + 0.2);

        $this->assertSame(1, $state['players']['p0']['x']);
        $this->assertSame(3, $state['players']['p1']['x']);
        unset($state['movement_intents']['p1']);
        $state['players']['p1']['y'] = 9;
        $engine->advanceMovement($state, $time + 1.6);
        $this->assertSame(1, $state['players']['p0']['x']);
    }

    public function test_quick_tap_preserves_one_step_even_when_stop_arrives_before_tick(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('p0', 'Host');
        $service->joinRoom($room->code, 'p1', 'Guest');
        $state = $service->startGame($room->code, 'p0')['state'];

        $service->handleActions($room->code, 'p0', [['type' => 'walk', 'direction' => 'right']], $state['round_id'], 1);
        $service->handleActions($room->code, 'p0', [['type' => 'stop']], $state['round_id'], 2);
        $this->travel(250)->milliseconds();
        $result = $service->tickRoom($room->code);

        $this->assertSame(2, $result['state']['players']['p0']['x']);
        $this->assertSame([], $result['state']['movement_intents']);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'player_moved');
    }

    public function test_all_four_spawns_have_a_real_escape_turn_even_at_maximum_block_density(): void
    {
        $this->freezeTime();
        $engine = new BomberGameEngine;
        $state = $engine->createInitialState([
            ['player_id' => 'p0', 'slot_index' => 0], ['player_id' => 'p1', 'slot_index' => 1],
            ['player_id' => 'p2', 'slot_index' => 2], ['player_id' => 'p3', 'slot_index' => 3],
        ], 42424, ['block_density_percent' => 100, 'powerup_drop_chance_percent' => 0]);
        $time = $state['started_at'];
        foreach (array_keys($state['players']) as $id) {
            $this->assertTrue($engine->handlePlaceBomb($state, $id)['success']);
        }

        for ($step = 0; $step < 3; $step++) {
            $directions = $step < 2
                ? ['p0' => 'right', 'p1' => 'left', 'p2' => 'left', 'p3' => 'right']
                : ['p0' => 'down', 'p1' => 'up', 'p2' => 'down', 'p3' => 'up'];
            foreach ($engine->resolveMovementTick($state, $directions, $time + 0.1 * $step) as $result) {
                $this->assertTrue($result['success']);
            }
        }
        $engine->checkAndDetonateExpiredBombs($state, $time + 2);

        $this->assertSame(1, $state['grid'][2][2]);
        foreach ($state['players'] as $player) {
            $this->assertTrue($player['alive']);
        }
        $this->assertSame([], $state['bombs']);
    }

    public function test_one_tap_with_a_delayed_duplicate_moves_one_tile_and_never_continues_autonomously(): void
    {
        $this->freezeTime();
        Event::fake([GameRoomEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('host_player', 'Host');
        $service->joinRoom($room->code, 'guest_player', 'Guest');
        $state = $service->startGame($room->code, 'host_player')['state'];
        $payload = ['round_id' => $state['round_id'], 'sequence' => 1, 'actions' => [['type' => 'move', 'direction' => 'right']]];

        $this->withHeader('X-Player-ID', 'host_player')->postJson('/api/rooms/'.$room->code.'/actions', $payload)->assertOk();
        $this->travel(300)->milliseconds();
        $this->postJson('/api/rooms/'.$room->code.'/actions', $payload)->assertOk()->assertJsonPath('duplicate', true);
        for ($tick = 0; $tick < 12; $tick++) {
            $this->travel(100)->milliseconds();
            $result = $service->tickRoom($room->code);
            $this->assertSame(2, $result['state']['players']['host_player']['x']);
            $this->assertSame(1, $result['state']['players']['host_player']['y']);
        }
        $this->assertSame([], $result['state']['movement_intents']);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'player_moved');
    }

    public static function diagnosticCases(): array
    {
        return ['anonymous' => [null, false], 'ordinary user' => [false, false], 'administrator' => [true, true]];
    }

    public static function disconnectCases(): array
    {
        return [
            'within grace' => [true, true, 1.5, true],
            'expired grace' => [true, true, 3.0, false],
            'reconnection disabled' => [true, false, 1.5, false],
            'elimination disabled' => [false, true, 3.0, true],
        ];
    }

    #[DataProvider('disconnectCases')]
    public function test_disconnect_policy_obeys_grace_reconnection_and_elimination_settings(bool $eliminate, bool $reconnect, float $elapsed, bool $alive): void
    {
        $this->freezeTime();
        $state = $this->state(4);
        $state['rules']['eliminate_on_disconnect'] = $eliminate;
        $state['rules']['reconnection_enabled'] = $reconnect;
        $state['rules']['reconnect_grace_seconds'] = 1;

        (new BomberGameEngine)->advanceMovement($state, $state['started_at'] + $elapsed);

        foreach ($state['players'] as $player) {
            $this->assertSame($alive, $player['alive']);
        }
    }

    #[DataProvider('diagnosticCases')]
    public function test_diagnostics_require_administrator_outside_local_environment(?bool $admin, bool $enabled): void
    {
        $room = app(RoomService::class)->createRoom('host_player', 'Host');
        if ($admin !== null) {
            $this->actingAs(User::factory()->make(['is_admin' => $admin]));
        }

        $this->get('/arena/'.$room->code)->assertOk()->assertSee('const diagnosticsAllowed = '.($enabled ? 'true' : 'false').';', false);
    }

    private function state(int $count): array
    {
        $players = [];
        for ($i = 0; $i < $count; $i++) {
            $players[] = ['player_id' => 'p'.$i, 'slot_index' => $i];
        }
        $state = (new BomberGameEngine)->createInitialState($players, 42424, ['powerup_drop_chance_percent' => 0]);
        $state['grid'] = array_fill(0, 11, array_fill(0, 13, 0));
        $state['grid'][0] = array_fill(0, 13, 1);

        return $state;
    }
}
