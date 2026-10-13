<?php

namespace App\Services;

use App\Events\GameRoomEvent;
use App\Models\GameMatchAudit;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoomService
{
    protected BomberGameEngine $engine;

    protected GameTelemetryService $telemetry;

    protected GameRulesService $rulesService;

    public const COLORS = ['neon-blue', 'neon-pink', 'neon-yellow', 'neon-green'];

    public function __construct(
        BomberGameEngine $engine,
        GameTelemetryService $telemetry,
        GameRulesService $rulesService
    ) {
        $this->engine = $engine;
        $this->telemetry = $telemetry;
        $this->rulesService = $rulesService;
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

            // Read currently active rules to configure room defaults
            $activeRules = $this->rulesService->getActiveRules();

            $room = Room::create([
                'code' => $code,
                'host_player_id' => $hostPlayerId,
                'status' => Room::STATE_WAITING,
                'max_players' => (int) ($activeRules['max_players'] ?? 4),
                'round_duration_seconds' => (int) ($activeRules['round_duration_seconds'] ?? 180),
                'map_seed' => mt_rand(10000, 99999),
                'rules_version' => $activeRules['__version'] ?? 1,
                'rules_snapshot' => $activeRules,
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

            $this->recordAudit($room->code, 'none', Room::STATE_WAITING, 'ROOM_CREATED', [
                'host_player_id' => $hostPlayerId,
                'nickname' => $nickname,
            ]);

            $this->telemetry->recordEvent(
                $room->code,
                'room_created',
                $nickname.' ('.substr($hostPlayerId, 0, 6).')',
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

        if (! $room) {
            return ['success' => false, 'error' => 'La sala no existe'];
        }

        if ($room->status !== Room::STATE_WAITING) {
            return ['success' => false, 'error' => 'La partida ya comenzó o finalizó'];
        }

        $existing = RoomPlayer::where('room_id', $room->id)
            ->where('player_id', $playerId)
            ->first();

        if ($existing) {
            $existing->update([
                'nickname' => $nickname,
                'last_seen_at' => now(),
            ]);
            $room->update(['last_activity_at' => now()]);

            return ['success' => true, 'room' => $room->load('players'), 'player' => $existing];
        }

        if ($room->isFull()) {
            return ['success' => false, 'error' => "La sala está completa (máximo {$room->max_players} jugadores)"];
        }

        $takenSlots = $room->players()->pluck('slot_index')->toArray();
        $slotIndex = 0;
        for ($i = 0; $i < 4; $i++) {
            if (! in_array($i, $takenSlots, true)) {
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
     * Toggle player ready status and update state machine (WAITING <-> READY).
     */
    public function toggleReady(string $code, string $playerId): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (! $room) {
            return ['success' => false, 'error' => 'Sala no encontrada'];
        }

        $player = RoomPlayer::where('room_id', $room->id)->where('player_id', $playerId)->first();
        if (! $player) {
            return ['success' => false, 'error' => 'Jugador no está en la sala'];
        }

        $newReady = ! $player->is_ready;
        $player->update(['is_ready' => $newReady, 'last_seen_at' => now()]);
        $room->update(['last_activity_at' => now()]);

        // Evaluate whether room is in READY or WAITING state
        $allPlayers = $room->players()->get();
        $allReady = ($allPlayers->count() >= 2) && $allPlayers->every(fn ($p) => $p->is_ready);

        if ($allReady && $room->status === Room::STATE_WAITING) {
            $prev = $room->status;
            $room->update(['status' => Room::STATE_READY]);
            $this->recordAudit($room->code, $prev, Room::STATE_READY, 'ALL_PLAYERS_READY');
        } elseif (! $allReady && $room->status === Room::STATE_READY) {
            $prev = $room->status;
            $room->update(['status' => Room::STATE_WAITING]);
            $this->recordAudit($room->code, $prev, Room::STATE_WAITING, 'PLAYER_UNREADY');
        }

        broadcast(new GameRoomEvent($room->code, 'player_ready', [
            'player_id' => $playerId,
            'is_ready' => $newReady,
            'room_status' => $room->status,
        ]));

        $this->telemetry->recordEvent(
            $room->code,
            'player_ready',
            $player->nickname,
            "arena.{$room->code}",
            ['player_id' => $playerId, 'is_ready' => $newReady]
        );

        return ['success' => true, 'is_ready' => $newReady, 'room_status' => $room->status];
    }

    /**
     * Start the game if host and requirements met.
     * Takes an immutable rules snapshot from current active rules.
     */
    public function startGame(string $code, string $playerId): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (! $room) {
            return ['success' => false, 'error' => 'Sala no encontrada'];
        }

        if (! $room->isHost($playerId)) {
            return ['success' => false, 'error' => 'Sólo el anfitrión puede iniciar la partida'];
        }

        // Fetch active rules snapshot at game launch
        $rulesSnapshot = $this->rulesService->getActiveRules();
        $minPlayers = (int) ($rulesSnapshot['min_players'] ?? 2);

        $players = $room->players()->get();
        if ($players->count() < $minPlayers) {
            return ['success' => false, 'error' => "Se necesitan al menos {$minPlayers} jugadores para iniciar"];
        }

        $records = $players->map(fn ($p) => [
            'player_id' => $p->player_id,
            'nickname' => $p->nickname,
            'slot_index' => $p->slot_index,
        ])->toArray();

        $initialState = $this->engine->createInitialState($records, $room->map_seed, $rulesSnapshot);
        Cache::put($this->stateKey($room->code), $initialState, 1800);

        $prevStatus = $room->status;
        $room->update([
            'status' => Room::STATE_PLAYING,
            'game_state' => $initialState,
            'rules_snapshot' => $rulesSnapshot,
            'rules_version' => $rulesSnapshot['__version'] ?? 1,
            'round_duration_seconds' => (int) ($rulesSnapshot['round_duration_seconds'] ?? 180),
            'started_at' => now(),
            'finished_at' => null,
            'finish_reason' => null,
            'winner_player_id' => null,
            'last_activity_at' => now(),
        ]);

        $this->recordAudit($room->code, $prevStatus, Room::STATE_PLAYING, 'HOST_STARTED_GAME', [
            'player_count' => count($records),
            'rules_version' => $rulesSnapshot['__version'] ?? 1,
            'duration' => $room->round_duration_seconds,
        ], count($records), $room->round_duration_seconds);

        broadcast(new GameRoomEvent($room->code, 'game_started', [
            'state' => $initialState,
            'duration' => $room->round_duration_seconds,
            'rules' => $rulesSnapshot,
        ]));

        $this->telemetry->recordEvent(
            $room->code,
            'game_started',
            'Host '.$playerId,
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

            if (! $state) {
                $room = Room::where('code', $cleanCode)->first();
                if (! $room || $room->status !== Room::STATE_PLAYING || ! $room->game_state) {
                    return ['success' => false, 'error' => 'Partida no activa'];
                }
                $state = $room->game_state;
            }

            if (! empty($state['is_game_over'])) {
                return ['success' => false, 'error' => 'Partida finalizada'];
            }

            $result = $mutation($state, $events, $telemetry);

            // Resolve any bomb whose authoritative server fuse has elapsed
            foreach ($this->engine->checkAndDetonateExpiredBombs($state) as $detRes) {
                $events[] = ['bomb_exploded', $detRes];
            }

            // Central evaluation of match outcome (e.g. check time limit or surviving players)
            $this->engine->evaluateMatchOutcome($state);

            // Ensure $result reflects final game_over status if determined
            if (is_array($result) && ! empty($state['is_game_over'])) {
                $result['is_game_over'] = true;
                $result['winner_id'] = $state['winner_id'] ?? null;
                $result['finish_reason'] = $state['finish_reason'] ?? null;
            }

            Cache::put($this->stateKey($cleanCode), $state, 1800);
        } finally {
            $lock->release();
        }

        // Authoritatively persist match completion and transition state to FINISHED (exactly once)
        if (! empty($state['is_game_over'])) {
            $finishReason = $state['finish_reason'] ?? Room::REASON_LAST_PLAYER_ALIVE;
            $stats = $state['stats'] ?? [];

            $room = Room::where('code', $cleanCode)->first();
            if ($room && $room->status !== Room::STATE_FINISHED) {
                $prevStatus = $room->status;
                $room->update([
                    'status' => Room::STATE_FINISHED,
                    'winner_player_id' => $state['winner_id'],
                    'finish_reason' => $finishReason,
                    'finished_at' => now(),
                    'game_state' => json_encode($state),
                    'total_bombs_detonated' => $stats['total_bombs_detonated'] ?? 0,
                    'total_blocks_destroyed' => $stats['total_blocks_destroyed'] ?? 0,
                    'total_eliminations' => $stats['total_eliminations'] ?? 0,
                    'last_activity_at' => now(),
                ]);

                $aliveCount = count(array_filter($state['players'] ?? [], fn ($p) => ! empty($p['alive'])));
                $this->recordAudit($cleanCode, $prevStatus, Room::STATE_FINISHED, $finishReason, [
                    'winner_id' => $state['winner_id'],
                    'stats' => $stats,
                ], $aliveCount, 0);

                $events[] = ['game_over', [
                    'winner_id' => $state['winner_id'],
                    'finish_reason' => $finishReason,
                    'players' => $state['players'],
                ]];
            }
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
     */
    public function handleActions(string $code, string $playerId, array $actions): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) use ($playerId, $actions) {
            if (! isset($state['players'][$playerId])) {
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
                        if (! empty($action['temp_id'])) {
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
                    'collected_powerup' => ! empty($collected),
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

    public function handleMove(string $code, string $playerId, string $direction): array
    {
        $result = $this->handleActions($code, $playerId, [['type' => 'move', 'direction' => $direction]]);
        if (($result['success'] ?? false) && ($result['rejected'] ?? 0) > 0) {
            return ['success' => false, 'error' => $result['last_error'] ?? 'Movimiento bloqueado', 'pos' => $result['pos']];
        }

        return $result;
    }

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
     */
    public function detonateBomb(string $code, string $bombId): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) use ($bombId) {
            if (! isset($state['bombs'][$bombId])) {
                return ['success' => true, 'already_exploded' => true];
            }

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
     */
    public function tickRoom(string $code): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) {
            return [
                'success' => true,
                'version' => $state['version'] ?? 1,
                'active_bombs' => count($state['bombs'] ?? []),
                'server_time' => microtime(true),
            ];
        });
    }

    /**
     * Cancel match administratively.
     */
    public function cancelMatch(string $code, string $adminName, ?string $reason = null): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (! $room) {
            return ['success' => false, 'error' => 'Sala no encontrada'];
        }

        $prevStatus = $room->status;
        $room->update([
            'status' => Room::STATE_CANCELLED,
            'finish_reason' => Room::REASON_ADMIN_CANCELLED,
            'finished_at' => now(),
            'last_activity_at' => now(),
        ]);

        $state = Cache::get($this->stateKey($room->code));
        if ($state) {
            $state['is_game_over'] = true;
            $state['finish_reason'] = Room::REASON_ADMIN_CANCELLED;
            Cache::put($this->stateKey($room->code), $state, 1800);
        }

        $this->recordAudit($room->code, $prevStatus, Room::STATE_CANCELLED, Room::REASON_ADMIN_CANCELLED, [
            'cancelled_by' => $adminName,
            'reason_note' => $reason ?: 'Cancelado desde panel de administración',
        ]);

        broadcast(new GameRoomEvent($room->code, 'game_over', [
            'winner_id' => null,
            'finish_reason' => Room::REASON_ADMIN_CANCELLED,
            'players' => $state['players'] ?? [],
        ]));

        return ['success' => true, 'room' => $room];
    }

    /**
     * Request rematch: reset room using current active rules snapshot.
     */
    public function requestRematch(string $code, string $playerId): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (! $room) {
            return ['success' => false, 'error' => 'Sala no encontrada'];
        }

        // Check if rematch is allowed in room rules
        $rules = $room->rules_snapshot ?? $this->rulesService->getActiveRules();
        if (isset($rules['allow_rematch']) && ! $rules['allow_rematch']) {
            return ['success' => false, 'error' => 'La revancha no está permitida según las reglas de la sala'];
        }

        // For rematch, we take the active rules snapshot (user preference: "toma esa configuracion al siguiente juego")
        $activeRules = $this->rulesService->getActiveRules();

        $players = $room->players()->get();
        $records = $players->map(fn ($p) => [
            'player_id' => $p->player_id,
            'nickname' => $p->nickname,
            'slot_index' => $p->slot_index,
        ])->toArray();

        $newSeed = mt_rand(10000, 99999);
        $initialState = $this->engine->createInitialState($records, $newSeed, $activeRules);
        Cache::put($this->stateKey($room->code), $initialState, 1800);

        $prevStatus = $room->status;
        $room->update([
            'status' => Room::STATE_PLAYING,
            'map_seed' => $newSeed,
            'rules_snapshot' => $activeRules,
            'rules_version' => $activeRules['__version'] ?? 1,
            'round_duration_seconds' => (int) ($activeRules['round_duration_seconds'] ?? 180),
            'game_state' => $initialState,
            'winner_player_id' => null,
            'finish_reason' => null,
            'started_at' => now(),
            'finished_at' => null,
            'last_activity_at' => now(),
        ]);

        $this->recordAudit($room->code, $prevStatus, Room::STATE_PLAYING, 'REMATCH_STARTED', [
            'initiated_by' => $playerId,
            'rules_version' => $activeRules['__version'] ?? 1,
        ], count($records), $room->round_duration_seconds);

        broadcast(new GameRoomEvent($room->code, 'game_started', [
            'state' => $initialState,
            'duration' => $room->round_duration_seconds,
            'is_rematch' => true,
            'rules' => $activeRules,
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

    /**
     * Record transition audit.
     */
    protected function recordAudit(
        string $roomCode,
        string $fromState,
        string $toState,
        string $reason,
        array $details = [],
        int $aliveCount = 0,
        int $remainingSeconds = 0
    ): void {
        try {
            GameMatchAudit::create([
                'room_code' => $roomCode,
                'from_state' => $fromState,
                'to_state' => $toState,
                'reason' => $reason,
                'details' => $details,
                'alive_count' => $aliveCount,
                'remaining_seconds' => $remainingSeconds,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
