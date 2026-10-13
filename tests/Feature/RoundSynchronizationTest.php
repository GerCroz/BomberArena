<?php

namespace Tests\Feature;

use App\Events\GameRoomEvent;
use App\Models\Room;
use App\Services\RoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class RoundSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rematch_resets_players_and_stats_and_accepts_new_bombs(): void
    {
        [$room, $state] = $this->createPlayingRoom();
        $state['players']['host_player']['alive'] = false;
        Cache::put("arena_state_{$room->code}", $state, 600);
        app(RoomService::class)->tickRoom($room->code);
        Event::fake([GameRoomEvent::class]);

        $response = $this->withHeader('X-Player-ID', 'host_player')->postJson("/api/rooms/{$room->code}/rematch", [
            'round_id' => $state['round_id'],
        ]);

        $response->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('state.version', 1)
            ->assertJsonPath('state.is_game_over', false)
            ->assertJsonPath('state.players.host_player.alive', true)
            ->assertJsonPath('state.players.guest_player.alive', true)
            ->assertJsonPath('state.bombs', []);
        $newRoundId = $response->json('state.round_id');
        $this->assertNotSame($state['round_id'], $newRoundId);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => Room::STATE_PLAYING, 'winner_player_id' => null, 'finish_reason' => null, 'total_eliminations' => 0]);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'game_started' && $event->data['round_id'] === $newRoundId);

        $this->postJson("/api/rooms/{$room->code}/actions", ['round_id' => $newRoundId, 'actions' => [['type' => 'bomb']]])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('bombs.0.owner_id', 'host_player');
        $this->assertCount(1, Cache::get("arena_state_{$room->code}")['bombs']);
    }

    #[TestWith(['actions', ['actions' => [['type' => 'bomb']]]])]
    #[TestWith(['tick', []])]
    #[TestWith(['detonate', ['bomb_id' => 'old_bomb']])]
    public function test_returns_422_and_preserves_new_round_for_delayed_commands(string $endpoint, array $payload): void
    {
        [$room, $state] = $this->createPlayingRoom();
        Event::fake([GameRoomEvent::class]);

        $response = $this->withHeader('X-Player-ID', 'host_player')->postJson("/api/rooms/{$room->code}/{$endpoint}", [...$payload, 'round_id' => 'previous_round']);

        $response->assertUnprocessable()->assertJsonPath('stale_round', true);
        $this->assertSame($state, Cache::get("arena_state_{$room->code}"));
        Event::assertNotDispatched(GameRoomEvent::class);
    }

    public function test_returns_422_without_restarting_an_active_round(): void
    {
        [$room, $state] = $this->createPlayingRoom();
        Event::fake([GameRoomEvent::class]);

        $response = $this->withHeader('X-Player-ID', 'host_player')->postJson("/api/rooms/{$room->code}/rematch", ['round_id' => $state['round_id']]);

        $response->assertUnprocessable()->assertJsonPath('error', 'La partida debe finalizar antes de pedir revancha');
        $this->assertSame($state, Cache::get("arena_state_{$room->code}"));
        Event::assertNotDispatched(GameRoomEvent::class);
    }

    public function test_returns_422_for_a_rematch_requested_by_a_non_participant(): void
    {
        [$room, $state] = $this->createPlayingRoom();
        app(RoomService::class)->cancelMatch($room->code, 'Admin');
        $cancelledState = Cache::get("arena_state_{$room->code}");
        Event::fake([GameRoomEvent::class]);

        $response = $this->withHeader('X-Player-ID', 'outsider_player')->postJson("/api/rooms/{$room->code}/rematch", ['round_id' => $state['round_id']]);

        $response->assertUnprocessable()->assertJsonPath('error', 'Jugador no está en la sala');
        $this->assertSame($cancelledState, Cache::get("arena_state_{$room->code}"));
        Event::assertNotDispatched(GameRoomEvent::class);
    }

    public function test_bomb_cannot_detonate_before_its_server_fuse_expires(): void
    {
        $this->freezeTime();
        [$room, $state] = $this->createPlayingRoom();
        $serverTime = (float) now()->format('U.u');
        $state['bombs']['early_bomb'] = [
            'id' => 'early_bomb', 'x' => 1, 'y' => 1, 'owner_id' => 'host_player',
            'range' => 1, 'planted_at' => $serverTime - 1.7, 'explodes_at' => $serverTime + 0.3, 'state' => 'active',
        ];
        Cache::put("arena_state_{$room->code}", $state, 600);
        Event::fake([GameRoomEvent::class]);

        $response = $this->postJson("/api/rooms/{$room->code}/detonate", ['round_id' => $state['round_id'], 'bomb_id' => 'early_bomb']);

        $response->assertOk()->assertJsonPath('pending', true);
        $this->assertSame($state, Cache::get("arena_state_{$room->code}"));
        Event::assertNotDispatched(GameRoomEvent::class);
    }

    public function test_simultaneous_expired_bombs_end_in_a_draw_with_no_dead_winner(): void
    {
        [$room, $state] = $this->createPlayingRoom();
        foreach (['host_player', 'guest_player'] as $playerId) {
            $player = $state['players'][$playerId];
            $state['bombs'][$playerId] = [
                'id' => $playerId, 'x' => $player['x'], 'y' => $player['y'], 'owner_id' => $playerId,
                'range' => 1, 'planted_at' => 1.0, 'explodes_at' => 2.0, 'state' => 'active',
            ];
        }
        Cache::put("arena_state_{$room->code}", $state, 600);
        Event::fake([GameRoomEvent::class]);

        $response = $this->postJson("/api/rooms/{$room->code}/tick", ['round_id' => $state['round_id']]);

        $response->assertOk()->assertJsonPath('winner_id', null)->assertJsonPath('finish_reason', Room::REASON_ALL_PLAYERS_ELIMINATED)
            ->assertJsonPath('events.0.data.winner_id', null)->assertJsonPath('events.1.data.winner_id', null);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => Room::STATE_FINISHED, 'winner_player_id' => null, 'total_eliminations' => 2]);
        $this->assertIsArray($room->fresh()->game_state);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->type === 'game_over' && $event->data['winner_id'] === null && $event->data['round_id'] === $state['round_id']);
    }

    public function test_tick_finishes_an_idle_round_at_timeout_without_bombs(): void
    {
        [$room, $state] = $this->createPlayingRoom();
        $state['started_at'] = 1.0;
        Cache::put("arena_state_{$room->code}", $state, 600);

        $response = $this->postJson("/api/rooms/{$room->code}/tick", ['round_id' => $state['round_id']]);

        $response->assertOk()->assertJsonPath('is_game_over', true)->assertJsonPath('finish_reason', Room::REASON_TIME_EXPIRED);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => Room::STATE_FINISHED, 'finish_reason' => Room::REASON_TIME_EXPIRED]);
    }

    public function test_action_response_contains_the_same_round_events_as_the_broadcast(): void
    {
        [$room, $state] = $this->createPlayingRoom();
        Event::fake([GameRoomEvent::class]);

        $response = $this->withHeader('X-Player-ID', 'host_player')->postJson("/api/rooms/{$room->code}/actions", ['round_id' => $state['round_id'], 'actions' => [['type' => 'bomb', 'temp_id' => 'optimistic_bomb']]]);

        $response->assertOk()->assertJsonPath('events.0.type', 'bomb_placed')->assertJsonPath('events.0.data.round_id', $state['round_id']);
        $eventData = $response->json('events.0.data');
        $this->assertNotEmpty($eventData['event_id']);
        Event::assertDispatched(GameRoomEvent::class, fn (GameRoomEvent $event): bool => $event->data === $eventData);
    }

    public function test_accepted_bomb_is_returned_even_if_broadcasting_fails(): void
    {
        [$room, $state] = $this->createPlayingRoom();
        Exceptions::fake([RuntimeException::class]);
        Event::listen(GameRoomEvent::class, function (GameRoomEvent $event): void {
            throw new RuntimeException('WebSocket unavailable');
        });

        $response = $this->withHeader('X-Player-ID', 'host_player')->postJson("/api/rooms/{$room->code}/actions", ['round_id' => $state['round_id'], 'actions' => [['type' => 'bomb']]]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('events.0.type', 'bomb_placed');
        $this->assertCount(1, Cache::get("arena_state_{$room->code}")['bombs']);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'WebSocket unavailable');
    }

    public function test_rematch_waits_for_the_room_lock_and_does_not_overwrite_in_flight_state(): void
    {
        [$room, $state] = $this->createPlayingRoom();
        app(RoomService::class)->cancelMatch($room->code, 'Admin');
        $cancelledState = Cache::get("arena_state_{$room->code}");
        $lock = Cache::lock("arena_lock_{$room->code}", 10);
        $this->assertTrue($lock->get());

        try {
            $response = $this->withHeader('X-Player-ID', 'host_player')->postJson("/api/rooms/{$room->code}/rematch", ['round_id' => $state['round_id']]);
        } finally {
            $lock->release();
        }

        $response->assertUnprocessable()->assertJsonPath('error', 'Servidor ocupado, reintenta');
        $this->assertSame($cancelledState, Cache::get("arena_state_{$room->code}"));
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => Room::STATE_CANCELLED]);
    }

    /**
     * @return array{0: Room, 1: array}
     */
    private function createPlayingRoom(): array
    {
        $service = app(RoomService::class);
        $room = $service->createRoom('host_player', 'Host');
        $service->joinRoom($room->code, 'guest_player', 'Guest');

        return [$room, $service->startGame($room->code, 'host_player')['state']];
    }
}
