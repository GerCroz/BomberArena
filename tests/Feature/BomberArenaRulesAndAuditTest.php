<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Services\BomberGameEngine;
use App\Services\GameRulesService;
use App\Services\RoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BomberArenaRulesAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected RoomService $roomService;

    protected GameRulesService $rulesService;

    protected BomberGameEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roomService = app(RoomService::class);
        $this->rulesService = app(GameRulesService::class);
        $this->engine = app(BomberGameEngine::class);
    }

    /**
     * Prueba 1: bombas sin eliminaciones
     * Iniciar una partida con dos jugadores.
     * Colocar una bomba. Destruir varios muros.
     * Verificar que ambos jugadores sigan vivos.
     * Confirmar que la partida continúe.
     */
    public function test_prueba_1_bombs_without_eliminations_keeps_match_playing(): void
    {
        $room = $this->roomService->createRoom('p1', 'Player 1');
        $this->roomService->joinRoom($room->code, 'p2', 'Player 2');
        $startRes = $this->roomService->startGame($room->code, 'p1');

        $state = $startRes['state'];
        // Ensure p1 is at safe spawn (1, 1) and p2 is at (11, 9)
        // Place destructible block at (5, 5) and bomb at (4, 5) far away from players
        $state['grid'][5][5] = BomberGameEngine::TILE_BLOCK;
        $state['players']['p1']['x'] = 1;
        $state['players']['p1']['y'] = 1;
        $state['players']['p2']['x'] = 11;
        $state['players']['p2']['y'] = 9;

        $bomb = [
            'id' => 'test_bomb_1',
            'x' => 4,
            'y' => 5,
            'owner_id' => 'p1',
            'range' => 2,
            'planted_at' => microtime(true) - 3,
            'explodes_at' => microtime(true) - 1,
            'state' => 'active',
        ];
        $state['bombs']['test_bomb_1'] = $bomb;
        Cache::put("arena_state_{$room->code}", $state, 600);

        // Detonate bomb
        $detRes = $this->roomService->detonateBomb($room->code, 'test_bomb_1');

        $this->assertTrue($detRes['success']);
        $freshState = Cache::get("arena_state_{$room->code}");

        // Block at (5, 5) was destroyed
        $this->assertEquals(BomberGameEngine::TILE_EMPTY, $freshState['grid'][5][5]);

        // Both players are alive and match must remain active!
        $this->assertTrue($freshState['players']['p1']['alive']);
        $this->assertTrue($freshState['players']['p2']['alive']);
        $this->assertFalse($freshState['is_game_over']);
        $this->assertNull($freshState['winner_id']);

        $freshRoom = $room->fresh();
        $this->assertEquals(Room::STATE_PLAYING, $freshRoom->status);
    }

    /**
     * Prueba 2: varias bombas sin daño
     * Iniciar una partida con cuatro jugadores.
     * Colocar bombas desde distintos jugadores.
     * Detonar varias bombas. No eliminar a ningún jugador.
     * Confirmar que la partida continúe hasta alcanzar una condición válida.
     */
    public function test_prueba_2_multiple_bombs_without_player_damage_keeps_playing(): void
    {
        $room = $this->roomService->createRoom('p1', 'Player 1');
        $this->roomService->joinRoom($room->code, 'p2', 'Player 2');
        $this->roomService->joinRoom($room->code, 'p3', 'Player 3');
        $this->roomService->joinRoom($room->code, 'p4', 'Player 4');
        $startRes = $this->roomService->startGame($room->code, 'p1');

        $state = $startRes['state'];
        // Put players in 4 safe corners: (1,1), (11,9), (11,1), (1,9)
        // Add 3 bombs in the center: (5,5), (7,5), (6,6)
        $now = microtime(true);
        $state['bombs']['b_center_1'] = [
            'id' => 'b_center_1', 'x' => 5, 'y' => 5, 'owner_id' => 'p1',
            'range' => 1, 'planted_at' => $now, 'explodes_at' => $now - 1, 'state' => 'active',
        ];
        $state['bombs']['b_center_2'] = [
            'id' => 'b_center_2', 'x' => 7, 'y' => 5, 'owner_id' => 'p2',
            'range' => 1, 'planted_at' => $now, 'explodes_at' => $now - 1, 'state' => 'active',
        ];
        Cache::put("arena_state_{$room->code}", $state, 600);

        // Tick room detonates both expired bombs
        $this->roomService->tickRoom($room->code);

        $freshState = Cache::get("arena_state_{$room->code}");
        $this->assertCount(4, array_filter($freshState['players'], fn ($p) => $p['alive']));
        $this->assertFalse($freshState['is_game_over']);
        $this->assertEquals(Room::STATE_PLAYING, $room->fresh()->status);
    }

    /**
     * Prueba 3: tiempo máximo
     * Iniciar una partida con dos jugadores.
     * Mantener ambos vivos.
     * Avanzar el reloj simulado hasta los 179 segundos.
     * Confirmar que continúa en PLAYING.
     * Avanzar hasta los 180 segundos.
     * Confirmar que finaliza por TIME_EXPIRED.
     */
    public function test_prueba_3_maximum_duration_timeout_terminates_with_time_expired(): void
    {
        $room = $this->roomService->createRoom('p1', 'Player 1');
        $this->roomService->joinRoom($room->code, 'p2', 'Player 2');
        $startRes = $this->roomService->startGame($room->code, 'p1');

        $state = $startRes['state'];
        $baseStartTime = 1000.0;
        $state['started_at'] = $baseStartTime;
        $state['duration_seconds'] = 180;

        // At 179 seconds: must still be running
        $this->assertFalse($this->engine->evaluateMatchOutcome($state, $baseStartTime + 179));
        $this->assertFalse($state['is_game_over']);

        // At 180 seconds: must finish by TIME_EXPIRED
        $this->assertTrue($this->engine->evaluateMatchOutcome($state, $baseStartTime + 180));
        $this->assertTrue($state['is_game_over']);
        $this->assertNull($state['winner_id']); // draw
        $this->assertEquals(Room::REASON_TIME_EXPIRED, $state['finish_reason']);
    }

    /**
     * Prueba 4: último superviviente
     * Iniciar una partida con cuatro jugadores.
     * Eliminar progresivamente a tres.
     * Confirmar que la partida finaliza cuando queda uno vivo.
     * Verificar que el ganador sea correcto.
     */
    public function test_prueba_4_last_survivor_wins_and_finishes_game(): void
    {
        $room = $this->roomService->createRoom('p1', 'Player 1');
        $this->roomService->joinRoom($room->code, 'p2', 'Player 2');
        $this->roomService->joinRoom($room->code, 'p3', 'Player 3');
        $this->roomService->joinRoom($room->code, 'p4', 'Player 4');
        $startRes = $this->roomService->startGame($room->code, 'p1');

        $state = $startRes['state'];
        // Eliminate p2, p3
        $state['players']['p2']['alive'] = false;
        $state['players']['p3']['alive'] = false;
        $this->assertFalse($this->engine->evaluateMatchOutcome($state));

        // Now eliminate p4, leaving only p1 alive
        $state['players']['p4']['alive'] = false;
        $this->assertTrue($this->engine->evaluateMatchOutcome($state));

        $this->assertTrue($state['is_game_over']);
        $this->assertEquals('p1', $state['winner_id']);
        $this->assertEquals(Room::REASON_LAST_PLAYER_ALIVE, $state['finish_reason']);
    }

    /**
     * Prueba 5: eliminación simultánea
     * Eliminar a todos los jugadores en la misma resolución.
     * Confirmar que el resultado sea empate.
     * Evitar que el orden de procesamiento genere un ganador incorrecto.
     */
    public function test_prueba_5_simultaneous_elimination_results_in_draw(): void
    {
        $room = $this->roomService->createRoom('p1', 'Player 1');
        $this->roomService->joinRoom($room->code, 'p2', 'Player 2');
        $startRes = $this->roomService->startGame($room->code, 'p1');

        $state = $startRes['state'];
        // Both players are on adjacent tiles caught in same bomb
        $state['players']['p1']['x'] = 5;
        $state['players']['p1']['y'] = 5;
        $state['players']['p2']['x'] = 6;
        $state['players']['p2']['y'] = 5;

        $state['bombs']['b_double'] = [
            'id' => 'b_double',
            'x' => 5,
            'y' => 5,
            'owner_id' => 'p1',
            'range' => 2,
            'planted_at' => microtime(true) - 3,
            'explodes_at' => microtime(true) - 1,
            'state' => 'active',
        ];
        Cache::put("arena_state_{$room->code}", $state, 600);

        $detRes = $this->roomService->detonateBomb($room->code, 'b_double');

        $this->assertTrue($detRes['success']);
        $this->assertTrue($detRes['is_game_over']);
        $this->assertNull($detRes['winner_id']); // Must be draw!
        $this->assertEquals(Room::REASON_ALL_PLAYERS_ELIMINATED, $detRes['finish_reason']);

        $freshRoom = $room->fresh();
        $this->assertEquals(Room::STATE_FINISHED, $freshRoom->status);
        $this->assertNull($freshRoom->winner_player_id);
        $this->assertEquals(Room::REASON_ALL_PLAYERS_ELIMINATED, $freshRoom->finish_reason);
    }

    /**
     * Prueba 6: cambios administrativos y snapshots inmutables
     * Iniciar una partida con duración de 180 segundos.
     * Cambiar la duración global a 300 segundos.
     * Confirmar que la partida iniciada conserva los 180 segundos.
     * Crear una nueva partida. Confirmar que utiliza 300 segundos.
     */
    public function test_prueba_6_administrative_rule_changes_use_immutable_snapshots(): void
    {
        // 1. Initial match with 180s default
        $room1 = $this->roomService->createRoom('p1', 'Player 1');
        $this->roomService->joinRoom($room1->code, 'p2', 'Player 2');
        $startRes1 = $this->roomService->startGame($room1->code, 'p1');
        $this->assertEquals(180, $startRes1['state']['duration_seconds']);
        $this->assertEquals(180, $room1->fresh()->round_duration_seconds);

        // 2. Admin changes duration to 300 seconds
        $currentRules = $this->rulesService->getActiveRules();
        $currentRules['round_duration_seconds'] = 300;
        $newRuleRecord = $this->rulesService->saveNewRules($currentRules, null, 'Admin Tester', 'Cambio a 300s');

        // 3. Confirm room 1 retains its 180 seconds immutable snapshot!
        $room1State = Cache::get("arena_state_{$room1->code}");
        $this->assertEquals(180, $room1State['duration_seconds']);
        $this->assertEquals(180, $room1->fresh()->round_duration_seconds);

        // 4. Create and start a new match -> uses 300 seconds
        $room2 = $this->roomService->createRoom('p3', 'Player 3');
        $this->roomService->joinRoom($room2->code, 'p4', 'Player 4');
        $startRes2 = $this->roomService->startGame($room2->code, 'p3');

        $this->assertEquals(300, $startRes2['state']['duration_seconds']);
        $this->assertEquals(300, $room2->fresh()->round_duration_seconds);
        $this->assertEquals($newRuleRecord->version, $room2->fresh()->rules_version);
    }

    /**
     * Prueba 7: eventos duplicados e idempotencia
     * Simular eventos de detonación repetidos.
     * Verificar que no disminuya dos veces ni finalice incorrectamente.
     */
    public function test_prueba_7_duplicate_detonations_are_idempotent(): void
    {
        $room = $this->roomService->createRoom('p1', 'Player 1');
        $this->roomService->joinRoom($room->code, 'p2', 'Player 2');
        $this->roomService->startGame($room->code, 'p1');

        $placeRes = $this->roomService->placeBomb($room->code, 'p1');
        $bombId = $placeRes['bomb']['id'];

        // Move p1 away from the bomb tile (1, 1) down to safe spot (1, 3)
        $this->roomService->handleMove($room->code, 'p1', 'down');
        // Clear block at (1, 3) if any and place p1 at (1, 4)
        $state = Cache::get("arena_state_{$room->code}");
        $state['grid'][4][1] = BomberGameEngine::TILE_EMPTY;
        $state['players']['p1']['x'] = 1;
        $state['players']['p1']['y'] = 4;
        $state['bombs'][$bombId]['explodes_at'] = microtime(true) - 1;
        Cache::put("arena_state_{$room->code}", $state, 600);

        // First detonation
        $res1 = $this->roomService->detonateBomb($room->code, $bombId);
        $this->assertTrue($res1['success']);

        // Duplicate detonation on already exploded bomb
        $res2 = $this->roomService->detonateBomb($room->code, $bombId);
        $this->assertTrue($res2['success']);
        $this->assertTrue($res2['already_exploded']);

        // Match remains playing with both players alive
        $freshState = Cache::get("arena_state_{$room->code}");
        $this->assertTrue($freshState['players']['p1']['alive']);
        $this->assertTrue($freshState['players']['p2']['alive']);
        $this->assertFalse($freshState['is_game_over']);
    }

    /**
     * Prueba 8: salas independientes
     * Crear dos partidas simultáneas.
     * Colocar bombas y eliminar bloques en una sala.
     * Confirmar que no se modifique el estado de la otra.
     */
    public function test_prueba_8_independent_rooms_isolate_state_and_timers(): void
    {
        $roomA = $this->roomService->createRoom('pa1', 'Alice');
        $this->roomService->joinRoom($roomA->code, 'pa2', 'Bob');
        $this->roomService->startGame($roomA->code, 'pa1');

        $roomB = $this->roomService->createRoom('pb1', 'Charlie');
        $this->roomService->joinRoom($roomB->code, 'pb2', 'David');
        $this->roomService->startGame($roomB->code, 'pb1');

        // Plant and explode bomb only in Room A
        $resBombA = $this->roomService->placeBomb($roomA->code, 'pa1');
        $bombAId = $resBombA['bomb']['id'];

        $stateA = Cache::get("arena_state_{$roomA->code}");
        $stateA['bombs'][$bombAId]['explodes_at'] = microtime(true) - 1;
        Cache::put("arena_state_{$roomA->code}", $stateA, 600);

        $this->roomService->detonateBomb($roomA->code, $bombAId);

        // Room B must remain completely untouched
        $stateB = Cache::get("arena_state_{$roomB->code}");
        $this->assertEmpty($stateB['bombs']);
        $this->assertTrue($stateB['players']['pb1']['alive']);
        $this->assertTrue($stateB['players']['pb2']['alive']);
        $this->assertFalse($stateB['is_game_over']);
        $this->assertEquals(Room::STATE_PLAYING, $roomB->fresh()->status);
    }
}
