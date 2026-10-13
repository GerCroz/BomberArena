<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomPlayer;
use App\Events\GameRoomEvent;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RoomService
{
    protected BomberGameEngine $engine;
    protected GameTelemetryService $telemetry;

    public const COLORS = ['neon-blue', 'neon-pink', 'neon-yellow', 'neon-green'];

    public function __construct(BomberGameEngine $engine, GameTelemetryService $telemetry)
    {
        $this->engine = $engine;
        $this->telemetry = $telemetry;
    }

    /**
     * Create a new room with a unique 6-character code.
     */
    public function createRoom(string $hostPlayerId, string $nickname): Room
    {
        return DB::transaction(function () use ($hostPlayerId, $nickname) {
            do {
                $code = strtoupper(Str::random(6));
            } while (Room::where('code', $code)->exists());

            $room = Room::create([
                'code' => $code,
                'host_player_id' => $hostPlayerId,
                'status' => 'waiting',
                'max_players' => 4,
                'round_duration_seconds' => 180,
                'map_seed' => mt_rand(10000, 99999),
                'last_activity_at' => now(),
            ]);

            RoomPlayer::create([
                'room_id' => $room->id,
                'player_id' => $hostPlayerId,
                'nickname' => $nickname,
                'color' => self::COLORS[0],
                'slot_index' => 0,
                'is_host' => true,
                'is_ready' => true, // Host is ready by default
                'is_alive' => true,
                'last_seen_at' => now(),
            ]);

            $this->telemetry->recordEvent(
                $room->code,
                'room_created',
                $nickname . ' (' . substr($hostPlayerId, 0, 6) . ')',
                "arena.{$room->code}",
                ['host_id' => $hostPlayerId, 'nickname' => $nickname]
            );

            return $room->load('players');
        });
    }

    /**
     * Join an existing room.
     */
    public function joinRoom(string $code, string $playerId, string $nickname): array
    {
        $room = Room::where('code', strtoupper($code))->first();

        if (!$room) {
            return ['success' => false, 'error' => 'La sala no existe'];
        }

        if ($room->status !== 'waiting') {
            return ['success' => false, 'error' => 'La partida ya comenzó o finalizó'];
        }

        $existing = RoomPlayer::where('room_id', $room->id)
            ->where('player_id', $playerId)
            ->first();

        if ($existing) {
            // Already in room, update last seen and nickname
            $existing->update([
                'nickname' => $nickname,
                'last_seen_at' => now(),
            ]);
            $room->update(['last_activity_at' => now()]);
            return ['success' => true, 'room' => $room->load('players'), 'player' => $existing];
        }

        if ($room->isFull()) {
            return ['success' => false, 'error' => 'La sala está completa (máximo 4 jugadores)'];
        }

        // Determine next available slot index 0..3
        $takenSlots = $room->players()->pluck('slot_index')->toArray();
        $slotIndex = 0;
        for ($i = 0; $i < 4; $i++) {
            if (!in_array($i, $takenSlots, true)) {
                $slotIndex = $i;
                break;
            }
        }

        $player = RoomPlayer::create([
            'room_id' => $room->id,
            'player_id' => $playerId,
            'nickname' => $nickname,
            'color' => self::COLORS[$slotIndex % 4],
            'slot_index' => $slotIndex,
            'is_host' => false,
            'is_ready' => false,
            'is_alive' => true,
            'last_seen_at' => now(),
        ]);

        $room->update(['last_activity_at' => now()]);

        // Broadcast player joined
        broadcast(new GameRoomEvent($room->code, 'player_joined', [
            'player' => $player->toArray(),
            'total_players' => $room->players()->count(),
        ]));

        $this->telemetry->recordEvent(
            $room->code,
            'player_joined',
            $nickname,
            "arena.{$room->code}",
            ['player' => $player->toArray()]
        );

        return ['success' => true, 'room' => $room->load('players'), 'player' => $player];
    }

    /**
     * Toggle player ready status.
     */
    public function toggleReady(string $code, string $playerId): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (!$room) {
            return ['success' => false, 'error' => 'Sala no encontrada'];
        }

        $player = RoomPlayer::where('room_id', $room->id)->where('player_id', $playerId)->first();
        if (!$player) {
            return ['success' => false, 'error' => 'Jugador no está en la sala'];
        }

        $newReady = !$player->is_ready;
        $player->update(['is_ready' => $newReady, 'last_seen_at' => now()]);
        $room->update(['last_activity_at' => now()]);

        broadcast(new GameRoomEvent($room->code, 'player_ready', [
            'player_id' => $playerId,
            'is_ready' => $newReady,
        ]));

        $this->telemetry->recordEvent(
            $room->code,
            'player_ready',
            $player->nickname,
            "arena.{$room->code}",
            ['player_id' => $playerId, 'is_ready' => $newReady]
        );

        return ['success' => true, 'is_ready' => $newReady];
    }

    /**
     * Start the game if host and requirements met.
     */
    public function startGame(string $code, string $playerId): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (!$room) {
            return ['success' => false, 'error' => 'Sala no encontrada'];
        }

        if (!$room->isHost($playerId)) {
            return ['success' => false, 'error' => 'Sólo el anfitrión puede iniciar la partida'];
        }

        $players = $room->players()->get();
        if ($players->count() < 2) {
            return ['success' => false, 'error' => 'Se necesitan al menos 2 jugadores para iniciar'];
        }

        // Initialize state via game engine
        $records = $players->map(fn($p) => [
            'player_id' => $p->player_id,
            'nickname' => $p->nickname,
            'slot_index' => $p->slot_index,
        ])->toArray();

        $initialState = $this->engine->createInitialState($records, $room->map_seed);
        \Illuminate\Support\Facades\Cache::put("arena_state_{$room->code}", $initialState, 600);

        $room->update([
            'status' => 'playing',
            'game_state' => $initialState,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        broadcast(new GameRoomEvent($room->code, 'game_started', [
            'state' => $initialState,
            'duration' => $room->round_duration_seconds,
        ]));

        $this->telemetry->recordEvent(
            $room->code,
            'game_started',
            'Host ' . $playerId,
            "arena.{$room->code}",
            ['players_count' => count($records), 'seed' => $room->map_seed]
        );

        return ['success' => true, 'state' => $initialState];
    }

    /**
     * Handle player move command with ultra-fast in-memory state.
     */
    public function handleMove(string $code, string $playerId, string $direction): array
    {
        $cleanCode = strtoupper($code);
        $state = \Illuminate\Support\Facades\Cache::get("arena_state_{$cleanCode}");

        if (!$state) {
            $room = Room::where('code', $cleanCode)->first();
            if (!$room || $room->status !== 'playing') {
                return ['success' => false, 'error' => 'Partida no activa'];
            }
            $state = $room->game_state;
        }

        if (!$state) {
            return ['success' => false, 'error' => 'Estado no inicializado'];
        }

        $result = $this->engine->handleMove($state, $playerId, $direction);
        if (!$result['success']) {
            return $result;
        }

        // Check and auto-detonate any bombs that hit their 2s timer
        $autoDetonations = $this->engine->checkAndDetonateExpiredBombs($state);
        foreach ($autoDetonations as $detRes) {
            broadcast(new GameRoomEvent($cleanCode, 'bomb_exploded', $detRes));
            if ($detRes['is_game_over']) {
                broadcast(new GameRoomEvent($cleanCode, 'game_over', [
                    'winner_id' => $detRes['winner_id'],
                    'players' => $state['players'],
                ]));
            }
        }

        // Cache update is instantaneous (sub-millisecond)
        \Illuminate\Support\Facades\Cache::put("arena_state_{$cleanCode}", $state, 600);

        // Broadcast player moved to other players
        broadcast(new GameRoomEvent($cleanCode, 'player_moved', [
            'player_id' => $playerId,
            'x' => $result['pos']['x'],
            'y' => $result['pos']['y'],
            'direction' => $direction,
            'collected_powerup' => $result['collected_powerup'],
            'stats' => $result['player'],
        ]));

        return $result;
    }

    /**
     * Handle place bomb command.
     */
    public function placeBomb(string $code, string $playerId): array
    {
        $cleanCode = strtoupper($code);
        $state = \Illuminate\Support\Facades\Cache::get("arena_state_{$cleanCode}");

        $room = Room::where('code', $cleanCode)->first();
        if (!$room || $room->status !== 'playing') {
            return ['success' => false, 'error' => 'Partida no activa'];
        }

        if (!$state) {
            $state = $room->game_state;
        }

        if (!$state) {
            return ['success' => false, 'error' => 'Estado no inicializado'];
        }

        $result = $this->engine->handlePlaceBomb($state, $playerId);
        if (!$result['success']) {
            return $result;
        }

        // Check for any expired bombs while placing
        $autoDetonations = $this->engine->checkAndDetonateExpiredBombs($state);
        foreach ($autoDetonations as $detRes) {
            broadcast(new GameRoomEvent($cleanCode, 'bomb_exploded', $detRes));
            if ($detRes['is_game_over']) {
                broadcast(new GameRoomEvent($cleanCode, 'game_over', [
                    'winner_id' => $detRes['winner_id'],
                    'players' => $state['players'],
                ]));
            }
        }

        \Illuminate\Support\Facades\Cache::put("arena_state_{$cleanCode}", $state, 600);

        broadcast(new GameRoomEvent($cleanCode, 'bomb_placed', [
            'bomb' => $result['bomb'],
        ]));

        $this->telemetry->recordEvent(
            $cleanCode,
            'bomb_placed',
            $playerId,
            "arena.{$cleanCode}",
            ['bomb' => $result['bomb']]
        );

        return $result;
    }

    /**
     * Detonate bomb and broadcast effects, eliminations and game over.
     */
    public function detonateBomb(string $code, string $bombId): array
    {
        $cleanCode = strtoupper($code);
        $state = \Illuminate\Support\Facades\Cache::get("arena_state_{$cleanCode}");

        if (!$state) {
            $room = Room::where('code', $cleanCode)->first();
            if (!$room || $room->status !== 'playing') {
                return ['success' => false, 'error' => 'Partida no activa'];
            }
            $state = $room->game_state;
        }

        if (!$state) {
            return ['success' => false, 'error' => 'Estado no inicializado'];
        }

        // If bomb was already exploded by timer or chain reaction, return cleanly
        if (!isset($state['bombs'][$bombId])) {
            return ['success' => true, 'already_exploded' => true];
        }

        $result = $this->engine->detonateBomb($state, $bombId);
        if (!$result['success']) {
            return $result;
        }

        // Also detonate any other expired bombs
        $autoDetonations = $this->engine->checkAndDetonateExpiredBombs($state);

        \Illuminate\Support\Facades\Cache::put("arena_state_{$cleanCode}", $state, 600);

        if ($result['is_game_over']) {
            $room = Room::where('code', $cleanCode)->first();
            if ($room) {
                $room->status = 'finished';
                $room->winner_player_id = $result['winner_id'];
                $room->finished_at = now();
                $room->game_state = $state;
                $room->last_activity_at = now();
                $room->save();
            }
        }

        broadcast(new GameRoomEvent($cleanCode, 'bomb_exploded', $result));

        foreach ($autoDetonations as $detRes) {
            broadcast(new GameRoomEvent($cleanCode, 'bomb_exploded', $detRes));
            if ($detRes['is_game_over']) {
                $result['is_game_over'] = true;
                $result['winner_id'] = $detRes['winner_id'];
            }
        }

        if ($result['is_game_over']) {
            broadcast(new GameRoomEvent($cleanCode, 'game_over', [
                'winner_id' => $result['winner_id'],
                'players' => $state['players'],
            ]));
        }

        $this->telemetry->recordEvent(
            $cleanCode,
            'bomb_exploded',
            'Engine',
            "arena.{$cleanCode}",
            [
                'bomb_id' => $bombId,
                'tiles' => count($result['explosion_tiles']),
                'destroyed_blocks' => count($result['destroyed_blocks']),
                'eliminated' => count($result['eliminated_players']),
                'game_over' => $result['is_game_over'],
            ]
        );

        return $result;
    }

    /**
     * Request rematch: reset room to waiting or restart game.
     */
    public function requestRematch(string $code, string $playerId): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (!$room) {
            return ['success' => false, 'error' => 'Sala no encontrada'];
        }

        $players = $room->players()->get();
        $records = $players->map(fn($p) => [
            'player_id' => $p->player_id,
            'nickname' => $p->nickname,
            'slot_index' => $p->slot_index,
        ])->toArray();

        $newSeed = mt_rand(10000, 99999);
        $initialState = $this->engine->createInitialState($records, $newSeed);

        $room->update([
            'status' => 'playing',
            'map_seed' => $newSeed,
            'game_state' => $initialState,
            'winner_player_id' => null,
            'started_at' => now(),
            'finished_at' => null,
            'last_activity_at' => now(),
        ]);

        broadcast(new GameRoomEvent($room->code, 'game_started', [
            'state' => $initialState,
            'duration' => $room->round_duration_seconds,
            'is_rematch' => true,
        ]));

        $this->telemetry->recordEvent(
            $room->code,
            'rematch_started',
            $playerId,
            "arena.{$room->code}",
            ['seed' => $newSeed]
        );

        return ['success' => true, 'state' => $initialState];
    }
}
