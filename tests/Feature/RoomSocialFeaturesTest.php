<?php

namespace Tests\Feature;

use App\Events\GameRoomEvent;
use App\Events\LobbyRoomsChanged;
use App\Events\RoomSocialEvent;
use App\Models\GameRule;
use App\Models\Room;
use App\Models\User;
use App\Services\CharacterCatalog;
use App\Services\GameRulesService;
use App\Services\PlayerIdentityService;
use App\Services\RoomService;
use App\Services\RoomSocialService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RoomSocialFeaturesTest extends TestCase
{
    use DatabaseMigrations;

    public function test_same_character_is_allowed_and_all_characters_keep_identical_initial_statistics(): void
    {
        [$room] = $this->room(4, ['neon-green', 'neon-green', 'neon-yellow', 'neon-pink']);
        $state = app(RoomService::class)->startGame($room->code, 'player_0')['state'];
        $this->assertSame('neon-green', $state['players']['player_0']['color']);
        $this->assertSame('neon-green', $state['players']['player_1']['character_id']);
        foreach ($state['players'] as $player) {
            $this->assertSame(1, $player['speed']);
            $this->assertSame(1, $player['max_bombs']);
            $this->assertSame(2, $player['bomb_range']);
        }
        Event::assertDispatched(GameRoomEvent::class, fn ($event): bool => $event->type === 'game_started');
    }

    public function test_four_distinct_characters_are_preserved_in_the_server_snapshot(): void
    {
        [$room] = $this->room(4, CharacterCatalog::ids());
        $state = app(RoomService::class)->startGame($room->code, 'player_0')['state'];
        $this->assertSame(CharacterCatalog::ids(), array_column($state['players'], 'character_id'));
    }

    public function test_rejoining_a_waiting_room_updates_the_character_without_adding_a_player(): void
    {
        [$room] = $this->room();
        $this->asPlayer('player_1')->postJson('/api/rooms/join', ['code' => $room->code, 'character_id' => 'neon-yellow'])->assertOk()->assertJsonPath('player.character_id', 'neon-yellow');
        $this->assertSame(2, $room->players()->count());
        Event::assertDispatched(RoomSocialEvent::class, fn ($event): bool => $event->type === 'player.character.updated' && $event->data['character_id'] === 'neon-yellow');
    }

    public function test_character_can_change_before_start_and_is_broadcast_privately(): void
    {
        [$room] = $this->room();
        $this->asPlayer('player_1')->postJson('/api/rooms/'.$room->code.'/character', ['character_id' => 'neon-yellow'])
            ->assertOk()->assertJsonPath('player.character_id', 'neon-yellow');
        $this->assertDatabaseHas('room_players', ['room_id' => $room->id, 'player_id' => 'player_1', 'character_id' => 'neon-yellow']);
        Event::assertDispatched(RoomSocialEvent::class, fn ($event): bool => $event->type === 'player.character.updated' && $event->broadcastOn()[0]->name === 'private-arena-social.'.$room->code);
    }

    public function test_invalid_character_is_rejected_on_create_join_and_change(): void
    {
        [$room] = $this->room();
        $this->asPlayer('player_2')->postJson('/api/rooms', ['character_id' => 'admin-super-speed'])->assertUnprocessable()->assertJsonValidationErrors('character_id');
        $this->postJson('/api/rooms/join', ['code' => $room->code, 'character_id' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('character_id');
        $this->asPlayer('player_1')->postJson('/api/rooms/'.$room->code.'/character', ['character_id' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('character_id');
        $this->assertSame(2, $room->players()->count());
    }

    public function test_character_cannot_change_during_play_or_from_another_identity(): void
    {
        [$room] = $this->room();
        $state = app(RoomService::class)->startGame($room->code, 'player_0')['state'];
        $this->asPlayer('player_1')->postJson('/api/rooms/'.$room->code.'/character', ['character_id' => 'neon-green'])->assertUnprocessable();
        $this->asPlayer('outsider_player')->postJson('/api/rooms/'.$room->code.'/character', ['character_id' => 'neon-green'])->assertForbidden();
        $this->assertSame($state, Cache::get('arena_state_'.$room->code));
    }

    public function test_public_listing_excludes_private_metadata_and_updates_for_public_changes_only(): void
    {
        [$room] = $this->room();
        $private = app(RoomService::class)->createRoom('private_player', 'Private', ['name' => 'Secret', 'is_private' => true]);
        $response = $this->getJson('/api/public-rooms')->assertOk()->assertJsonCount(1, 'rooms');
        $response->assertJsonPath('rooms.0.name', 'Test Arena')->assertJsonPath('rooms.0.available', true);
        $this->assertSame(['name', 'code', 'host', 'players_count', 'connected_count', 'max_players', 'status', 'available'], array_keys($response->json('rooms.0')));
        $response->assertDontSee($private->code);
        Event::assertDispatchedTimes(LobbyRoomsChanged::class, 2);
        Event::assertDispatched(LobbyRoomsChanged::class, fn ($event): bool => $event->data === []);
    }

    public function test_listing_tracks_capacity_start_finish_and_explicit_leave(): void
    {
        [$room] = $this->room(2, [], 2);
        $this->getJson('/api/public-rooms')->assertJsonPath('rooms.0.available', false);
        $this->asPlayer('player_2')->postJson('/api/rooms/join', ['code' => $room->code])->assertUnprocessable();
        $state = app(RoomService::class)->startGame($room->code, 'player_0')['state'];
        $this->getJson('/api/public-rooms')->assertJsonPath('rooms.0.status', Room::STATE_PLAYING)->assertJsonPath('rooms.0.available', false);
        $this->finish($room, $state);
        $this->getJson('/api/public-rooms')->assertJsonPath('rooms.0.status', Room::STATE_FINISHED);
        $this->asPlayer('player_1')->postJson('/api/rooms/'.$room->code.'/leave')->assertOk();
        $this->asPlayer('player_0')->postJson('/api/rooms/'.$room->code.'/leave')->assertOk();
        $this->getJson('/api/public-rooms')->assertJsonCount(0, 'rooms');
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => Room::STATE_CANCELLED]);
    }

    public function test_two_claims_for_the_last_place_cannot_both_succeed_and_started_rooms_reject_join(): void
    {
        [$room] = $this->room(3);
        $service = app(RoomService::class);
        $this->assertTrue($service->joinRoom($room->code, 'last_player_a', 'A')['success']);
        $this->assertFalse($service->joinRoom($room->code, 'last_player_b', 'B')['success']);
        $this->assertSame(4, $room->players()->count());
        $service->startGame($room->code, 'player_0');
        $this->assertFalse($service->joinRoom($room->code, 'last_player_c', 'C')['success']);
    }

    public function test_winner_cannot_request_and_loser_request_needs_acceptance(): void
    {
        [$room, $state] = $this->finishedRoom();
        $this->asPlayer('player_0')->postJson('/api/rooms/'.$room->code.'/rematch', ['round_id' => $state['round_id']])->assertUnprocessable();
        $vote = $this->asPlayer('player_1')->postJson('/api/rooms/'.$room->code.'/rematch', ['round_id' => $state['round_id']])->assertOk()->json('rematch');
        $this->assertSame('pending', $vote['status']);
        $this->assertSame($state['round_id'], Cache::get('arena_state_'.$room->code)['round_id']);
        $response = $this->asPlayer('player_0')->postJson('/api/rooms/'.$room->code.'/rematch/respond', ['request_id' => $vote['id'], 'round_id' => $state['round_id'], 'accept' => true])->assertOk()->assertJsonPath('state.is_game_over', false);
        $this->assertNotSame($state['round_id'], $response->json('state.round_id'));
        $this->assertSame(1, $room->fresh()->rematch_count);
        $this->assertSame('neon-green', $response->json('state.players.player_1.character_id'));
        $this->assertSame([], $response->json('state.bombs'));
        Event::assertDispatched(RoomSocialEvent::class, fn ($event): bool => $event->type === 'rematch.started');
    }

    public function test_winner_rejection_does_not_restart_the_room(): void
    {
        [$room, $state] = $this->finishedRoom();
        $vote = app(RoomSocialService::class)->requestRematch($room->code, 'player_1', $state['round_id'])['rematch'];
        $this->asPlayer('player_0')->postJson('/api/rooms/'.$room->code.'/rematch/respond', ['request_id' => $vote['id'], 'round_id' => $state['round_id'], 'accept' => false])->assertOk()->assertJsonPath('rematch.status', 'rejected');
        $this->assertSame($state, Cache::get('arena_state_'.$room->code));
        $this->assertSame(Room::STATE_FINISHED, $room->fresh()->status);
    }

    public function test_four_players_require_all_votes_and_duplicates_cannot_start_twice(): void
    {
        [$room, $state] = $this->finishedRoom(4);
        $social = app(RoomSocialService::class);
        $first = $social->requestRematch($room->code, 'player_1', $state['round_id']);
        $this->assertFalse($social->requestRematch($room->code, 'player_2', $state['round_id'])['success']);
        $vote = $first['rematch'];
        foreach (['player_0', 'player_2'] as $id) {
            $result = $social->respond($room->code, $id, $vote['id'], $state['round_id'], true);
            $this->assertSame('pending', $result['rematch']['status']);
        }
        $result = $social->respond($room->code, 'player_3', $vote['id'], $state['round_id'], true);
        $this->assertTrue($result['success']);
        $round = $result['state']['round_id'];
        $this->assertFalse($social->respond($room->code, 'player_3', $vote['id'], $state['round_id'], true)['success']);
        $this->assertSame($round, Cache::get('arena_state_'.$room->code)['round_id']);
        $this->assertSame(1, $room->fresh()->rematch_count);
    }

    public function test_disconnect_during_vote_cancels_without_restarting(): void
    {
        [$room, $state] = $this->finishedRoom(3);
        $social = app(RoomSocialService::class);
        $vote = $social->requestRematch($room->code, 'player_1', $state['round_id'])['rematch'];
        $room->players()->where('player_id', 'player_2')->update(['last_seen_at' => now()->subSeconds(31)]);
        $this->assertFalse($social->respond($room->code, 'player_0', $vote['id'], $state['round_id'], true)['success']);
        $this->assertSame('cancelled', $room->fresh()->rematch_request['status']);
        $this->assertSame($state, Cache::get('arena_state_'.$room->code));
    }

    public function test_draw_allows_any_participant_but_still_requires_other_votes(): void
    {
        [$room, $state] = $this->finishedRoom(2, null);
        $social = app(RoomSocialService::class);
        $vote = $social->requestRematch($room->code, 'player_0', $state['round_id'])['rematch'];
        $this->assertSame('pending', $vote['status']);
        $this->assertTrue($social->respond($room->code, 'player_1', $vote['id'], $state['round_id'], true)['success']);
    }

    public function test_leave_or_expiration_cancels_a_pending_request(): void
    {
        $this->freezeTime();
        [$room, $state] = $this->finishedRoom();
        $social = app(RoomSocialService::class);
        $social->requestRematch($room->code, 'player_1', $state['round_id']);
        $this->travel(31)->seconds();
        $social->heartbeat($room->code, 'player_0');
        $this->assertSame('cancelled', $room->fresh()->rematch_request['status']);
        Event::assertDispatched(RoomSocialEvent::class, fn ($event): bool => $event->type === 'rematch.cancelled');
    }

    public function test_rematch_authorization_uses_cookie_membership_not_an_arbitrary_header(): void
    {
        [$room, $state] = $this->finishedRoom();
        $this->withHeader('X-Player-ID', 'player_1')->postJson('/api/rooms/'.$room->code.'/rematch', ['round_id' => $state['round_id']])->assertForbidden();
        $this->asPlayer('outsider_player')->postJson('/api/rooms/'.$room->code.'/rematch', ['round_id' => $state['round_id']])->assertForbidden();
        $this->assertNull($room->fresh()->rematch_request);
    }

    public function test_private_social_channel_requires_membership_and_valid_channel(): void
    {
        [$room] = $this->room();
        $payload = ['channel_name' => 'private-arena-social.'.$room->code, 'socket_id' => '123.456'];
        $this->postJson('/api/arena/broadcasting/auth', $payload)->assertForbidden();
        $this->asPlayer('outsider_player')->postJson('/api/arena/broadcasting/auth', $payload)->assertForbidden();
        $this->asPlayer('player_0')->postJson('/api/arena/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/api/arena/broadcasting/auth', [...$payload, 'channel_name' => 'private-secret.admin'])->assertUnprocessable();
    }

    public function test_abandoned_rooms_expire_and_host_transfer_keeps_a_waiting_room_usable(): void
    {
        $this->freezeTime();
        [$room] = $this->room();
        app(RoomSocialService::class)->leave($room->code, 'player_0');
        $this->assertSame('player_1', $room->fresh()->host_player_id);
        $this->travel(301)->seconds();
        $this->artisan('arena:rooms')->assertSuccessful();
        $this->assertSame(Room::STATE_CANCELLED, $room->fresh()->status);
        $this->assertSame(0, $room->players()->count());
        Event::assertDispatched(LobbyRoomsChanged::class, fn ($event): bool => $event->type === 'room.closed');
    }

    #[TestWith(['character_selection_enabled', false])]
    #[TestWith(['duplicate_characters_allowed', false])]
    public function test_administrative_character_rules_are_enforced(string $rule, bool $enabled): void
    {
        app(GameRulesService::class)->saveNewRules([$rule => $enabled]);
        [$room] = $this->room(1);
        if ($rule === 'character_selection_enabled') {
            $this->asPlayer('player_0')->postJson('/api/rooms/'.$room->code.'/character', ['character_id' => 'neon-green'])->assertUnprocessable();
        } else {
            $this->asPlayer('player_1')->postJson('/api/rooms/join', ['code' => $room->code, 'character_id' => 'neon-blue'])->assertUnprocessable();
        }
    }

    public function test_social_admin_settings_are_validated_and_do_not_change_active_room_snapshots(): void
    {
        [$room] = $this->room();
        $before = $room->rules_snapshot;
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $settings = [...GameRule::defaultSettings(), 'rematch_response_seconds' => 1];
        $this->postJson(route('admin.rules.update'), $settings)->assertUnprocessable()->assertJsonValidationErrors('rematch_response_seconds');
        $this->postJson(route('admin.rules.update'), [...$settings, 'rematch_response_seconds' => 40, 'public_rooms_enabled' => false])->assertOk()->assertJsonPath('settings.rematch_response_seconds', 40);
        $this->assertSame($before, $room->fresh()->rules_snapshot);
        $this->get(route('admin.rules'))->assertOk()->assertSee('rematch_response_seconds', false);
        $this->getJson('/api/public-rooms')->assertJsonCount(0, 'rooms');
    }

    private function asPlayer(string $id): static
    {
        return $this->withCredentials()->withCookie(PlayerIdentityService::COOKIE_NAME, $id);
    }

    public function test_browser_identity_cannot_be_minted_from_a_supplied_player_id(): void
    {
        [$room] = $this->room();
        $response = $this->withHeader('X-Player-ID', 'player_0')->get('/arena/'.$room->code);
        $response->assertOk()->assertViewHas('inputToken', null);
        $this->assertNotSame('player_0', $response->viewData('playerId'));
    }

    public function test_disconnected_waiting_players_release_places_while_an_active_host_keeps_the_room(): void
    {
        $this->freezeTime();
        [$room] = $this->room(4);
        $this->travel(31)->seconds();
        app(RoomSocialService::class)->heartbeat($room->code, 'player_0');
        $this->artisan('arena:rooms')->assertSuccessful();
        $this->assertSame(1, $room->players()->count());
        $this->assertSame(Room::STATE_WAITING, $room->fresh()->status);
        $this->getJson('/api/public-rooms')->assertJsonPath('rooms.0.available', true)->assertJsonPath('rooms.0.players_count', 1);
        Event::assertDispatched(RoomSocialEvent::class, fn ($event): bool => $event->type === 'room.player_left' && count($event->data['player_ids']) === 3);
    }

    #[TestWith(['allow_rematch', false])]
    #[TestWith(['max_consecutive_rematches', 1])]
    public function test_rematch_administrative_limits_are_checked(string $key, bool|int $value): void
    {
        [$room, $state] = $this->finishedRoom();
        $room->update(['rules_snapshot' => [...$room->rules_snapshot, $key => $value], 'rematch_count' => 1]);
        $result = app(RoomSocialService::class)->requestRematch($room->code, 'player_1', $state['round_id']);
        $this->assertFalse($result['success']);
        $this->assertNull($room->fresh()->rematch_request);
    }

    public function test_draw_can_be_disabled_and_underfilled_votes_cannot_start_a_round(): void
    {
        [$room, $state] = $this->finishedRoom(2, null);
        $room->update(['rules_snapshot' => [...$room->rules_snapshot, 'rematch_on_draw' => false]]);
        $social = app(RoomSocialService::class);
        $this->assertFalse($social->requestRematch($room->code, 'player_0', $state['round_id'])['success']);
        $room->update(['rules_snapshot' => [...$room->rules_snapshot, 'rematch_on_draw' => true]]);
        $social->leave($room->code, 'player_1');
        $this->assertFalse($social->requestRematch($room->code, 'player_0', $state['round_id'])['success']);
        $this->assertFalse(app(RoomService::class)->startConfirmedRematch($room->code, 'player_0', $state['round_id'])['success']);
    }

    public function test_hidden_playing_rooms_and_disabled_private_creation_are_enforced(): void
    {
        app(GameRulesService::class)->saveNewRules(['private_rooms_enabled' => false, 'show_playing_rooms' => false]);
        [$room] = $this->room();
        $this->asPlayer('player_2')->postJson('/api/rooms', ['is_private' => true])->assertUnprocessable()->assertJsonValidationErrors('is_private');
        app(RoomService::class)->startGame($room->code, 'player_0');
        $this->getJson('/api/public-rooms')->assertJsonCount(0, 'rooms');
    }

    private function room(int $count = 2, array $characters = [], int $capacity = 4): array
    {
        Event::fake([GameRoomEvent::class, LobbyRoomsChanged::class, RoomSocialEvent::class]);
        $service = app(RoomService::class);
        $room = $service->createRoom('player_0', 'Player 0', ['name' => 'Test Arena', 'max_players' => $capacity, 'character_id' => $characters[0] ?? 'neon-blue']);
        for ($i = 1; $i < $count; $i++) {
            $result = $service->joinRoom($room->code, 'player_'.$i, 'Player '.$i, $characters[$i] ?? 'neon-green');
            $this->assertTrue($result['success']);
        }

        return [$room->fresh()];
    }

    private function finishedRoom(int $count = 2, ?string $winner = 'player_0'): array
    {
        [$room] = $this->room($count);
        $state = app(RoomService::class)->startGame($room->code, 'player_0')['state'];

        return [$room, $this->finish($room, $state, $winner)];
    }

    private function finish(Room $room, array $state, ?string $winner = 'player_0'): array
    {
        foreach ($state['players'] as $id => &$player) {
            $player['alive'] = $id === $winner;
        }
        unset($player);
        Cache::put('arena_state_'.$room->code, $state, 1800);

        return app(RoomService::class)->tickRoom($room->code)['state'];
    }
}
