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
    private array $lastMutationTimes = [];

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
        return $this->withRoomLock($code, fn (): array => $this->joinLockedRoom($code, $playerId, $nickname));
    }

    protected function joinLockedRoom(string $code, string $playerId, string $nickname): array
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

        $this->broadcastGameEvent(new GameRoomEvent($room->code, 'player_joined', [
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

        $this->broadcastGameEvent(new GameRoomEvent($room->code, 'player_ready', [
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
        return $this->withRoomLock($code, fn (): array => $this->startLockedGame($code, $playerId));
    }

    protected function startLockedGame(string $code, string $playerId): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (! $room) {
            return ['success' => false, 'error' => 'Sala no encontrada'];
        }

        if (! $room->isHost($playerId)) {
            return ['success' => false, 'error' => 'Sólo el anfitrión puede iniciar la partida'];
        }

        if (! in_array($room->status, [Room::STATE_WAITING, Room::STATE_READY], true)) {
            return ['success' => false, 'error' => 'La partida ya comenzó; usa la revancha al finalizar'];
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

        $this->broadcastGameEvent(new GameRoomEvent($room->code, 'game_started', [
            'state' => $initialState,
            'round_id' => $initialState['round_id'],
            'server_time' => microtime(true),
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

        return ['success' => true, 'state' => $initialState, 'server_time' => microtime(true)];
    }

    /**
     * Cache key holding the live, authoritative match state.
     */
    protected function stateKey(string $code): string
    {
        return "arena_state_{$code}";
    }

    protected function withRoomLock(string $code, callable $operation): array
    {
        try {
            return Cache::lock('arena_lock_'.strtoupper($code), 10)->block(3, $operation);
        } catch (LockTimeoutException $exception) {
            return ['success' => false, 'error' => 'Servidor ocupado, reintenta'];
        }
    }

    /**
     * Run a state mutation atomically under a per-room lock.
     */
    protected function mutateState(string $code, callable $mutation, ?string $roundId = null): array
    {
        $mutationStarted = hrtime(true);
        $cleanCode = strtoupper($code);
        $lock = Cache::lock("arena_lock_{$cleanCode}", 10);

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

            if ($roundId !== null && $roundId !== ($state['round_id'] ?? null)) {
                return ['success' => false, 'error' => 'La ronda cambió; sincroniza la partida', 'stale_round' => true, 'state' => $state, 'server_time' => microtime(true)];
            }

            if (! empty($state['is_game_over'])) {
                return ['success' => false, 'error' => 'Partida finalizada', 'state' => $state, 'server_time' => microtime(true)];
            }

            $previousVersion = $state['version'];
            if (app()->environment('local')) {
                $state['diagnostics']['previous_mutation_ms'] = $this->lastMutationTimes[$cleanCode] ?? 0;
            }
            foreach ($state['players'] as $id => $player) {
                $input = Cache::get(ArenaInputService::key($cleanCode, $state['round_id'], $id));
                if ($player['alive'] && $input && $input['sequence'] > ($state['input_ack'][$id] ?? 0)) {
                    $inputReceivedAt = $input['received_at'];
                    $previousInput = $state['current_inputs'][$id] ?? null;
                    if ($previousInput && $previousInput['direction'] === $input['direction'] && $previousInput['expires_at'] >= $input['received_at']) {
                        $input['received_at'] = $previousInput['received_at'];
                    }
                    $state['current_inputs'][$id] = $input;
                    $state['input_ack'][$id] = $input['sequence'];
                    $state['players'][$id]['last_seen_at'] = $inputReceivedAt;
                    $state['version']++;
                }
            }

            $simulationStarted = hrtime(true);
            array_push($events, ...$this->engine->advanceMovement($state, (float) now()->format('U.u')));
            if (app()->environment('local')) {
                $state['diagnostics']['simulation_ms'] = (hrtime(true) - $simulationStarted) / 1_000_000;
            }
            if (empty($state['is_game_over'])) {
                $result = $mutation($state, $events, $telemetry);
            } else {
                $result = ['success' => true, 'state' => $state];
            }

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

            $publishTime = (float) now()->format('U.u');
            $publishInterval = 1 / (int) ($state['rules']['publish_hz'] ?? 10);
            if ($state['version'] > ($state['last_published_version'] ?? $previousVersion) && ($publishTime - ($state['last_published_at'] ?? 0) >= $publishInterval || ! empty($state['is_game_over']))) {
                $state['last_published_at'] = $publishTime;
                $state['last_published_version'] = $state['version'];
                $events[] = ['state_updated', ['state' => $state]];
            }
            Cache::put($this->stateKey($cleanCode), $state, 1800);
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
                        'game_state' => $state,
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

        } finally {
            $lock->release();
        }

        $result['round_id'] = $state['round_id'] ?? null;
        $result['version'] = $state['version'] ?? 1;
        $result['tick'] = $state['tick'] ?? 0;
        $result['state'] = $state;
        $result['server_time'] = microtime(true);
        $result['events'] = [];
        foreach ($events as [$type, $data]) {
            if (is_array($data)) {
                $data['version'] = $state['version'] ?? 1;
                $data['server_time'] = microtime(true);
                $data['round_id'] = $state['round_id'] ?? null;
                $data['event_id'] = (string) Str::uuid();
            }
            $result['events'][] = ['type' => $type, 'data' => $data];
            $this->broadcastGameEvent(new GameRoomEvent($cleanCode, $type, $data));
        }

        foreach ($telemetry as [$name, $source, $payload]) {
            $this->telemetry->recordEvent($cleanCode, $name, $source, "arena.{$cleanCode}", $payload);
        }

        $this->lastMutationTimes[$cleanCode] = (hrtime(true) - $mutationStarted) / 1_000_000;

        return $result;
    }

    /**
     * Process an ordered batch of player actions (moves and bombs) atomically.
     */
    public function updateInput(string $code, string $playerId, string $roundId, int $sequence, ?string $direction, float $sampledAt): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) use ($playerId, $sequence, $direction, $sampledAt): array {
            if (! isset($state['players'][$playerId]) || ! $state['players'][$playerId]['alive']) {
                return ['success' => false, 'error' => 'Jugador no activo'];
            }
            $time = (float) now()->format('U.u');
            if ($sequence <= ($state['input_ack'][$playerId] ?? 0)) {
                return ['success' => true, 'duplicate' => true];
            }
            if ($direction !== null && ($time - $sampledAt > 0.5 || $sampledAt > $time + 0.1)) {
                return ['success' => false, 'stale_input' => true, 'error' => 'Entrada vencida'];
            }
            $previous = $state['current_inputs'][$playerId] ?? null;
            $receivedAt = $previous && $previous['direction'] === $direction && $previous['expires_at'] >= $time ? $previous['received_at'] : $time;
            $state['current_inputs'][$playerId] = ['direction' => $direction, 'sequence' => $sequence, 'received_at' => $receivedAt, 'expires_at' => $direction === null ? $time : min($time + 0.5, $sampledAt + 0.5)];
            $state['input_ack'][$playerId] = $sequence;
            $state['players'][$playerId]['last_seen_at'] = $time;
            unset($state['movement_intents'][$playerId]);
            $state['version']++;
            if (! isset($state['simulated_at']) || $state['simulated_at'] < $time - 0.1) {
                $state['simulated_at'] = $time;
            }

            return ['success' => true, 'input_ack' => $sequence];
        }, $roundId);
    }

    public function handleActions(string $code, string $playerId, array $actions, ?string $roundId = null, ?int $sequence = null): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) use ($playerId, $actions, $sequence) {
            if (! isset($state['players'][$playerId])) {
                return ['success' => false, 'error' => 'Jugador no encontrado'];
            }
            if ($state['players'][$playerId]['alive']) {
                $state['players'][$playerId]['last_seen_at'] = (float) now()->format('U.u');
            }

            if ($sequence !== null) {
                if ($sequence <= ($state['input_sequences'][$playerId] ?? 0)) {
                    return ['success' => true, 'duplicate' => true];
                }
                $state['input_sequences'][$playerId] = $sequence;
            }

            $bombs = [];
            $rejected = 0;
            $lastError = null;
            $queuedMovement = false;

            foreach (array_slice($actions, 0, 40) as $action) {
                $type = $action['type'] ?? null;

                if ($type === 'stop') {
                    unset($state['movement_intents'][$playerId]);
                } elseif ($type === 'walk') {
                    $direction = (string) ($action['direction'] ?? '');
                    if ($state['players'][$playerId]['alive'] && in_array($direction, ['up', 'down', 'left', 'right'], true)) {
                        $time = (float) now()->format('U.u');
                        if (! isset($state['movement_intents'][$playerId]) || $state['movement_intents'][$playerId]['direction'] !== $direction) {
                            $tick = max($state['tick'] ?? 0, (int) floor(($time - $state['started_at']) / BomberGameEngine::TICK_SECONDS)) + 1;
                            $state['pending_moves'][$tick][$playerId] = $direction;
                        }
                        $state['movement_intents'][$playerId] = ['direction' => $direction, 'started_at' => $time, 'expires_at' => $time + 1.0];
                    } else {
                        $rejected++;
                        $lastError = 'Direccion de movimiento invalida';
                    }
                } elseif ($type === 'move') {
                    $direction = (string) ($action['direction'] ?? '');
                    $tick = max($state['tick'] ?? 0, (int) floor(((float) now()->format('U.u') - $state['started_at']) / BomberGameEngine::TICK_SECONDS)) + 1;
                    if ($queuedMovement || ($sequence === null && isset($state['pending_moves'][$tick][$playerId])) || ! $state['players'][$playerId]['alive'] || ! in_array($direction, ['up', 'down', 'left', 'right'], true)) {
                        $rejected++;
                        $lastError = 'Comando de movimiento rechazado';
                    } else {
                        $state['pending_moves'][$tick][$playerId] = $direction;
                        $queuedMovement = true;
                        $state['version']++;
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
        }, $roundId);
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
    public function detonateBomb(string $code, string $bombId, ?string $roundId = null): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) use ($bombId) {
            if (! isset($state['bombs'][$bombId])) {
                return ['success' => true, 'already_exploded' => true];
            }

            if ((float) now()->format('U.u') < $state['bombs'][$bombId]['explodes_at']) {
                return ['success' => true, 'pending' => true];
            }

            $result = ['success' => true, 'already_exploded' => true];
            foreach ($this->engine->checkAndDetonateExpiredBombs($state) as $detonation) {
                $events[] = ['bomb_exploded', $detonation];
                if (in_array($bombId, $detonation['detonated_bomb_ids'], true)) {
                    $result = $detonation;
                }
            }

            return $result;
        }, $roundId);
    }

    /**
     * Authoritative central room simulation tick.
     */
    public function tickRoom(string $code, ?string $roundId = null, ?string $playerId = null): array
    {
        return $this->mutateState($code, function (array &$state, array &$events, array &$telemetry) use ($playerId) {
            if ($playerId !== null && ! empty($state['players'][$playerId]['alive'])) {
                $state['players'][$playerId]['last_seen_at'] = (float) now()->format('U.u');
            }

            return [
                'success' => true,
                'version' => $state['version'] ?? 1,
                'active_bombs' => count($state['bombs'] ?? []),
                'state' => $state,
                'server_time' => microtime(true),
            ];
        }, $roundId);
    }

    /**
     * Cancel match administratively.
     */
    public function cancelMatch(string $code, string $adminName, ?string $reason = null): array
    {
        return $this->withRoomLock($code, fn (): array => $this->cancelLockedMatch($code, $adminName, $reason));
    }

    protected function cancelLockedMatch(string $code, string $adminName, ?string $reason): array
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

        $this->broadcastGameEvent(new GameRoomEvent($room->code, 'game_over', [
            'round_id' => $state['round_id'] ?? null,
            'winner_id' => null,
            'finish_reason' => Room::REASON_ADMIN_CANCELLED,
            'players' => $state['players'] ?? [],
        ]));

        return ['success' => true, 'room' => $room];
    }

    /**
     * Request rematch: reset room using current active rules snapshot.
     */
    public function requestRematch(string $code, string $playerId, ?string $roundId = null): array
    {
        return $this->withRoomLock($code, fn (): array => $this->startLockedRematch($code, $playerId, $roundId));
    }

    protected function startLockedRematch(string $code, string $playerId, ?string $roundId): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (! $room) {
            return ['success' => false, 'error' => 'Sala no encontrada'];
        }

        if (! $room->players()->where('player_id', $playerId)->exists()) {
            return ['success' => false, 'error' => 'Jugador no está en la sala'];
        }

        if (! in_array($room->status, [Room::STATE_FINISHED, Room::STATE_CANCELLED], true)) {
            return ['success' => false, 'error' => 'La partida debe finalizar antes de pedir revancha'];
        }

        $previousState = Cache::get($this->stateKey($room->code)) ?? $room->game_state;
        if ($roundId !== null && $roundId !== ($previousState['round_id'] ?? null)) {
            return ['success' => false, 'error' => 'La ronda cambió; sincroniza la partida', 'stale_round' => true];
        }

        // Check if rematch is allowed in room rules
        if (($previousState['movement_model'] ?? null) !== 'classic-v2' && (empty($previousState['winner_id']) || $previousState['winner_id'] === $playerId)) {
            return ['success' => false, 'error' => 'Solo los perdedores pueden solicitar revancha'];
        }

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
            'total_bombs_detonated' => 0,
            'total_blocks_destroyed' => 0,
            'total_eliminations' => 0,
            'last_activity_at' => now(),
        ]);

        $this->recordAudit($room->code, $prevStatus, Room::STATE_PLAYING, 'REMATCH_STARTED', [
            'initiated_by' => $playerId,
            'rules_version' => $activeRules['__version'] ?? 1,
        ], count($records), $room->round_duration_seconds);

        $this->broadcastGameEvent(new GameRoomEvent($room->code, 'game_started', [
            'state' => $initialState,
            'round_id' => $initialState['round_id'],
            'server_time' => microtime(true),
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

        return ['success' => true, 'state' => $initialState, 'server_time' => microtime(true)];
    }

    /**
     * Keep authoritative HTTP responses available when broadcasting fails.
     */
    protected function broadcastGameEvent(GameRoomEvent $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $exception) {
            report($exception);
        }
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
