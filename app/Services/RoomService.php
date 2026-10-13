<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomPlayer;
use App\Events\GameRoomEvent;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
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
     * Cache key holding the live, authoritative match state.
     */
    protected function stateKey(string $code): string
    {
        return "arena_state_{$code}";
    }

    /**
     * Run a state mutation atomically under a per-room lock.
     *
     * Without this lock, concurrent requests (moves, bombs, detonations from
     * several players) read the same snapshot and the last writer silently
     * erased the others' changes — the root cause of "bombs that never appear".
     *
     * The callback receives the state by reference plus an events collector.
     * Broadcasts and telemetry are dispatched AFTER the lock is released so
     * network I/O never blocks other players.
     */
    protected function mutateState(string $code, callable $mutation): array
    {
        $cleanCode = strtoupper($code);
        $lock = Cache::lock("arena_lock_{$cleanCode}", 5);

        try {
            $lock->block(3);
        } catch (LockTimeoutException $e) {
            return ['success' => false, 'error' => 'Servidor ocupado, reintenta'];
        }

        $events = [];
        $telemetry = [];

        try {
            $state = Cache::get($this->stateKey($cleanCode));

            if (!$state) {
                $room = Room::where('code', $cleanCode)->first();
                if (!$room || $room->status !== 'playing' || !$room->game_state) {
                    return ['success' => false, 'error' => 'Partida no activa'];
                }
                $state = $room->game_state;
            }

            if (!empty($state['is_game_over'])) {
                return ['success' => false, 'error' => 'Partida finalizada'];
            }

            $result = $mutation($state, $events, $telemetry);

            // Resolve any bomb whose authoritative server fuse has elapsed
            foreach ($this->engine->checkAndDetonateExpiredBombs($state) as $detRes) {
                $events[] = ['bomb_exploded', $detRes];
            }

            Cache::put($this->stateKey($cleanCode), $state, 1800);
        } finally {
            $lock->release();
        }

        // Persist final result only once, when the match ends
        if (!empty($state['is_game_over'])) {
            Room::where('code', $cleanCode)->update([
                'status' => 'finished',
                'winner_player_id' => $state['winner_id'],
                'finished_at' => now(),
                'game_state' => json_encode($state),
                'last_activity_at' => now(),
            ]);
            $events[] = ['game_over', [
                'winner_id' => $state['winner_id'],
                'players' => $state['players'],
            ]];
        }

        foreach ($events as [$type, $data]) {
            if (is_array($data)) {
                $data['version'] = $state['version'] ?? 1;
                $data['server_time'] = microtime(true);
            }
            broadcast(new GameRoomEvent($cleanCode, $type, $data));
        }

        foreach ($telemetry as [$name, $source, $payload]) {
            $this->telemetry->recordEvent($cleanCode, $name, $source, "arena.{$cleanCode}", $payload);
        }

        return $result;
    }

    /**
     * Process an ordered batch of player actions (moves and bombs) atomically.
     *
     * The client accumulates inputs while a request is in flight and sends them
     * together, so network latency never piles up a backlog of requests and a
     * bomb is always planted on the exact tile the player was standing on.
     */
    public function handleActions(string $code, string $playerId, array $actions): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) use ($playerId, $actions) {
            if (!isset($state['players'][$playerId])) {
                return ['success' => false, 'error' => 'Jugador no encontrado'];
            }

            $moved = false;
            $lastDirection = null;
            $collected = [];
            $bombs = [];
            $rejected = 0;
            $lastError = null;

            foreach (array_slice($actions, 0, 40) as $action) {
                $type = $action['type'] ?? null;

                if ($type === 'move') {
                    $res = $this->engine->handleMove($state, $playerId, (string) ($action['direction'] ?? ''));
                    if ($res['success']) {
                        $moved = true;
                        $lastDirection = $action['direction'];
                        if ($res['collected_powerup']) {
                            $collected[] = "{$res['pos']['x']}_{$res['pos']['y']}";
                        }
                    } else {
                        $rejected++;
                        $lastError = $res['error'] ?? 'Movimiento bloqueado';
                    }
                } elseif ($type === 'bomb') {
                    $res = $this->engine->handlePlaceBomb($state, $playerId);
                    if ($res['success']) {
                        $bombData = $res['bomb'];
                        if (!empty($action['temp_id'])) {
                            $bombData['temp_id'] = $action['temp_id'];
                        }
                        $bombs[] = $bombData;
                    } else {
                        $rejected++;
                        $lastError = $res['error'] ?? 'No se pudo colocar la bomba';
                    }
                }
            }

            $player = $state['players'][$playerId];

            if ($moved) {
                $events[] = ['player_moved', [
                    'player_id' => $playerId,
                    'x' => $player['x'],
                    'y' => $player['y'],
                    'direction' => $lastDirection,
                    'collected_powerup' => !empty($collected),
                    'collected_keys' => $collected,
                    'stats' => $player,
                ]];
            }

            foreach ($bombs as $bomb) {
                $events[] = ['bomb_placed', ['bomb' => $bomb]];
                $telemetry[] = ['bomb_placed', $playerId, ['bomb' => $bomb]];
            }

            return [
                'success' => true,
                'pos' => ['x' => $player['x'], 'y' => $player['y']],
                'alive' => $player['alive'],
                'bombs' => $bombs,
                'rejected' => $rejected,
                'last_error' => $lastError,
            ];
        });
    }

    /**
     * Handle a single player move (kept for API compatibility).
     */
    public function handleMove(string $code, string $playerId, string $direction): array
    {
        $result = $this->handleActions($code, $playerId, [['type' => 'move', 'direction' => $direction]]);
        if (($result['success'] ?? false) && ($result['rejected'] ?? 0) > 0) {
            return ['success' => false, 'error' => $result['last_error'] ?? 'Movimiento bloqueado', 'pos' => $result['pos']];
        }
        return $result;
    }

    /**
     * Handle a single bomb placement (kept for API compatibility).
     */
    public function placeBomb(string $code, string $playerId): array
    {
        $result = $this->handleActions($code, $playerId, [['type' => 'bomb']]);
        if (($result['success'] ?? false) && empty($result['bombs'])) {
            return ['success' => false, 'error' => 'No se pudo colocar la bomba'];
        }
        return ($result['success'] ?? false)
            ? ['success' => true, 'bomb' => $result['bombs'][0]]
            : $result;
    }

    /**
     * Detonate a bomb whose fuse has elapsed according to the SERVER clock.
     * Clients only "ping" this endpoint; early requests are ignored.
     */
    public function detonateBomb(string $code, string $bombId): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) use ($bombId) {
            if (!isset($state['bombs'][$bombId])) {
                return ['success' => true, 'already_exploded' => true];
            }

            // Server is authoritative: allow 350ms tolerance for clock jitter
            if (microtime(true) + 0.35 < $state['bombs'][$bombId]['explodes_at']) {
                return ['success' => true, 'pending' => true];
            }

            $result = $this->engine->detonateBomb($state, $bombId);
            if ($result['success']) {
                $events[] = ['bomb_exploded', $result];
                $telemetry[] = ['bomb_exploded', 'Engine', [
                    'bomb_id' => $bombId,
                    'tiles' => count($result['explosion_tiles']),
                    'destroyed_blocks' => count($result['destroyed_blocks']),
                    'eliminated' => count($result['eliminated_players']),
                    'game_over' => $result['is_game_over'],
                ]];
            }

            return $result;
        });
    }

    /**
     * Authoritative central room simulation tick.
     * Evaluates expired bombs and progresses state.
     */
    public function tickRoom(string $code): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) {
            // Expired bombs are checked and detonated automatically by mutateState post-callback.
            return [
                'success' => true,
                'version' => $state['version'] ?? 1,
                'active_bombs' => count($state['bombs'] ?? []),
                'server_time' => microtime(true),
            ];
        });
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
        Cache::put($this->stateKey($room->code), $initialState, 1800);

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
