<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Services\RoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
}
