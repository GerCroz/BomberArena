<?php

namespace Tests\Feature;

use App\Services\RoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RoomServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RoomService $roomService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roomService = app(RoomService::class);
    }

    public function test_can_create_room_with_host(): void
    {
        $room = $this->roomService->createRoom('host_123', 'Capitán');

        $this->assertNotNull($room->code);
        $this->assertEquals(6, strlen($room->code));
        $this->assertEquals('host_123', $room->host_player_id);
        $this->assertEquals('waiting', $room->status);
        $this->assertCount(1, $room->players);
        $this->assertTrue($room->players->first()->is_host);
        $this->assertTrue($room->players->first()->is_ready);
    }

    public function test_can_join_room_up_to_max_four_players(): void
    {
        $room = $this->roomService->createRoom('p0', 'Host');

        $res1 = $this->roomService->joinRoom($room->code, 'p1', 'Player 1');
        $this->assertTrue($res1['success']);

        $res2 = $this->roomService->joinRoom($room->code, 'p2', 'Player 2');
        $this->assertTrue($res2['success']);

        $res3 = $this->roomService->joinRoom($room->code, 'p3', 'Player 3');
        $this->assertTrue($res3['success']);

        $this->assertEquals(4, $room->fresh()->players()->count());

        // 5th player must be rejected
        $res4 = $this->roomService->joinRoom($room->code, 'p4', 'Player 4');
        $this->assertFalse($res4['success']);
        $this->assertStringContainsString('completa', $res4['error']);
    }

    public function test_cannot_start_game_with_only_one_player(): void
    {
        $room = $this->roomService->createRoom('p0', 'Solo');
        $res = $this->roomService->startGame($room->code, 'p0');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('al menos 2 jugadores', $res['error']);
    }

    public function test_can_start_game_with_two_players_and_initializes_state(): void
    {
        $room = $this->roomService->createRoom('p0', 'Host');
        $this->roomService->joinRoom($room->code, 'p1', 'Challenger');

        $res = $this->roomService->startGame($room->code, 'p0');
        $this->assertTrue($res['success']);
        $this->assertNotNull($res['state']);

        $room = $room->fresh();
        $this->assertEquals('playing', $room->status);
        $this->assertIsArray($room->game_state);
        $this->assertCount(2, $room->game_state['players']);
    }

    public function test_can_batch_actions_with_moves_and_bomb(): void
    {
        $room = $this->roomService->createRoom('p0', 'Host');
        $this->roomService->joinRoom($room->code, 'p1', 'Challenger');
        $this->roomService->startGame($room->code, 'p0');

        $actions = [
            ['type' => 'bomb', 'temp_id' => 'temp_opt_123'],
            ['type' => 'move', 'direction' => 'right'],
        ];

        $res = $this->roomService->handleActions($room->code, 'p0', $actions);

        $this->assertTrue($res['success']);
        $this->assertNotEmpty($res['bombs']);
        $this->assertEquals('temp_opt_123', $res['bombs'][0]['temp_id']);
        $this->assertNotNull($res['pos']);
    }

    public function test_tick_room_detonates_expired_bombs_authoritatively_and_returns_server_time(): void
    {
        $room = $this->roomService->createRoom('p0', 'Host');
        $this->roomService->joinRoom($room->code, 'p1', 'Challenger');
        $startRes = $this->roomService->startGame($room->code, 'p0');

        $state = $startRes['state'];
        $now = microtime(true);
        $state['bombs']['b_expired'] = [
            'id' => 'b_expired',
            'x' => 5,
            'y' => 5,
            'owner_id' => 'p0',
            'range' => 1,
            'planted_at' => $now - 3,
            'explodes_at' => $now - 1,
            'state' => 'active',
        ];
        Cache::put("arena_state_{$room->code}", $state, 600);

        $tickRes = $this->roomService->tickRoom($room->code);

        $this->assertTrue($tickRes['success']);
        $this->assertArrayHasKey('server_time', $tickRes);
        $freshState = Cache::get("arena_state_{$room->code}");
        $this->assertArrayNotHasKey('b_expired', $freshState['bombs']);
    }

    public function test_two_players_attempting_to_occupy_same_tile_are_both_rejected(): void
    {
        $this->freezeTime();
        $room = $this->roomService->createRoom('p0', 'Host');
        $this->roomService->joinRoom($room->code, 'p1', 'Challenger');
        $startRes = $this->roomService->startGame($room->code, 'p0');

        $state = $startRes['state'];
        // Place p0 at (1, 1) and p1 at (3, 1). Target tile between them is (2, 1).
        $state['rules']['player_collisions'] = true;
        $state['players']['p0']['x'] = 1;
        $state['players']['p0']['y'] = 1;
        $state['players']['p1']['x'] = 3;
        $state['players']['p1']['y'] = 1;
        Cache::put("arena_state_{$room->code}", $state, 600);

        // p0 moves right to (2, 1) -> succeeds
        $res0 = $this->roomService->handleMove($room->code, 'p0', 'right');
        $this->assertTrue($res0['success']);

        // p1 moves left towards (2, 1) -> now occupied by p0, must be rejected
        $res1 = $this->roomService->handleMove($room->code, 'p1', 'left');
        $this->assertTrue($res1['success']);
        $this->travel(100)->milliseconds();
        $result = $this->roomService->tickRoom($room->code);
        $this->assertSame(1, $result['state']['players']['p0']['x']);
        $this->assertSame(3, $result['state']['players']['p1']['x']);
        $this->assertSame(2, $result['state']['diagnostics']['rejected_collisions']);
    }

    public function test_detonate_bomb_is_idempotent_if_already_exploded(): void
    {
        $room = $this->roomService->createRoom('p0', 'Host');
        $this->roomService->joinRoom($room->code, 'p1', 'Challenger');
        $this->roomService->startGame($room->code, 'p0');

        $res = $this->roomService->detonateBomb($room->code, 'non_existent_bomb_id');

        $this->assertTrue($res['success']);
        $this->assertTrue($res['already_exploded']);
    }

    public function test_two_players_can_place_bombs_independently_without_interference(): void
    {
        $room = $this->roomService->createRoom('p0', 'Host');
        $this->roomService->joinRoom($room->code, 'p1', 'Challenger');
        $startRes = $this->roomService->startGame($room->code, 'p0');

        $state = $startRes['state'];
        $state['players']['p0']['x'] = 1;
        $state['players']['p0']['y'] = 1;
        $state['players']['p1']['x'] = 5;
        $state['players']['p1']['y'] = 5;
        Cache::put("arena_state_{$room->code}", $state, 600);

        $res0 = $this->roomService->placeBomb($room->code, 'p0');
        $this->assertTrue($res0['success']);
        $this->assertNotNull($res0['bomb']);
        $this->assertEquals('p0', $res0['bomb']['owner_id']);
        $this->assertEquals('active', $res0['bomb']['state']);

        $res1 = $this->roomService->placeBomb($room->code, 'p1');
        $this->assertTrue($res1['success']);
        $this->assertNotNull($res1['bomb']);
        $this->assertEquals('p1', $res1['bomb']['owner_id']);
        $this->assertEquals('active', $res1['bomb']['state']);

        $this->assertNotEquals($res0['bomb']['id'], $res1['bomb']['id']);

        $freshState = Cache::get("arena_state_{$room->code}");
        $this->assertCount(2, $freshState['bombs']);
        $this->assertArrayHasKey($res0['bomb']['id'], $freshState['bombs']);
        $this->assertArrayHasKey($res1['bomb']['id'], $freshState['bombs']);
    }
}
