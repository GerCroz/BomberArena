<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\BomberGameEngine;

class BomberGameEngineTest extends TestCase
{
    protected BomberGameEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new BomberGameEngine();
    }

    public function test_initial_state_generates_correct_dimensions_and_safe_spawns(): void
    {
        $players = [
            ['player_id' => 'p1', 'nickname' => 'Alice', 'slot_index' => 0],
            ['player_id' => 'p2', 'nickname' => 'Bob', 'slot_index' => 1],
        ];

        $state = $this->engine->createInitialState($players, 42424);

        $this->assertCount(BomberGameEngine::HEIGHT, $state['grid']);
        $this->assertCount(BomberGameEngine::WIDTH, $state['grid'][0]);

        // Spawns must be empty (safe)
        $this->assertEquals(BomberGameEngine::TILE_EMPTY, $state['grid'][1][1]);
        $this->assertEquals(BomberGameEngine::TILE_EMPTY, $state['grid'][1][2]);
        $this->assertEquals(BomberGameEngine::TILE_EMPTY, $state['grid'][2][1]);

        // Fixed pillars (e.g. at 2,2)
        $this->assertEquals(BomberGameEngine::TILE_WALL, $state['grid'][2][2]);

        // Players present
        $this->assertArrayHasKey('p1', $state['players']);
        $this->assertArrayHasKey('p2', $state['players']);
        $this->assertEquals(1, $state['players']['p1']['x']);
        $this->assertEquals(1, $state['players']['p1']['y']);
        $this->assertTrue($state['players']['p1']['alive']);
    }

    public function test_movement_respects_walls_and_boundaries(): void
    {
        $players = [
            ['player_id' => 'p1', 'nickname' => 'Alice', 'slot_index' => 0],
        ];

        $state = $this->engine->createInitialState($players, 42424);

        // Moving into top wall (y=0) should fail
        $res = $this->engine->handleMove($state, 'p1', 'up');
        $this->assertFalse($res['success']);
        $this->assertEquals(1, $state['players']['p1']['y']);

        // Moving into left wall (x=0) should fail
        $res = $this->engine->handleMove($state, 'p1', 'left');
        $this->assertFalse($res['success']);
        $this->assertEquals(1, $state['players']['p1']['x']);

        // Moving right to (2, 1) should succeed because (2,1) is safe zone (empty)
        $res = $this->engine->handleMove($state, 'p1', 'right');
        $this->assertTrue($res['success']);
        $this->assertEquals(2, $state['players']['p1']['x']);
        $this->assertEquals(1, $state['players']['p1']['y']);

        // Moving down from (2, 1) to (2, 2) hits fixed pillar -> should fail
        $res = $this->engine->handleMove($state, 'p1', 'down');
        $this->assertFalse($res['success']);
        $this->assertEquals(2, $state['players']['p1']['x']);
        $this->assertEquals(1, $state['players']['p1']['y']);
    }

    public function test_bomb_placement_and_limit(): void
    {
        $players = [
            ['player_id' => 'p1', 'nickname' => 'Alice', 'slot_index' => 0],
        ];

        $state = $this->engine->createInitialState($players, 42424);

        $res = $this->engine->handlePlaceBomb($state, 'p1');
        $this->assertTrue($res['success']);
        $this->assertCount(1, $state['bombs']);

        // Second bomb with max_bombs = 1 should fail
        $res2 = $this->engine->handlePlaceBomb($state, 'p1');
        $this->assertFalse($res2['success']);
        $this->assertStringContainsString('Límite', $res2['error']);
    }

    public function test_detonation_eliminates_player_and_declares_winner(): void
    {
        $players = [
            ['player_id' => 'p1', 'nickname' => 'Alice', 'slot_index' => 0],
            ['player_id' => 'p2', 'nickname' => 'Bob', 'slot_index' => 1],
        ];

        $state = $this->engine->createInitialState($players, 42424);

        // Place bomb at (1, 1) by p1
        $bombRes = $this->engine->handlePlaceBomb($state, 'p1');
        $bombId = $bombRes['bomb']['id'];

        // p1 stays at (1, 1). Detonate bomb
        $detRes = $this->engine->detonateBomb($state, $bombId);

        $this->assertTrue($detRes['success']);
        $this->assertFalse($state['players']['p1']['alive']);
        $this->assertTrue($state['players']['p2']['alive']);
        $this->assertTrue($detRes['is_game_over']);
        $this->assertEquals('p2', $detRes['winner_id']);
    }

    public function test_chain_reaction_detonation(): void
    {
        $players = [
            ['player_id' => 'p1', 'nickname' => 'Alice', 'slot_index' => 0],
            ['player_id' => 'p2', 'nickname' => 'Bob', 'slot_index' => 1],
        ];

        $state = $this->engine->createInitialState($players, 42424);

        // Bomb 1 at (1, 1)
        $b1 = [
            'id' => 'b1',
            'x' => 1,
            'y' => 1,
            'owner_id' => 'p1',
            'range' => 2,
            'planted_at' => microtime(true),
            'explodes_at' => microtime(true) + 2,
        ];
        // Bomb 2 at (2, 1) adjacent
        $b2 = [
            'id' => 'b2',
            'x' => 2,
            'y' => 1,
            'owner_id' => 'p2',
            'range' => 2,
            'planted_at' => microtime(true),
            'explodes_at' => microtime(true) + 2,
        ];

        $state['bombs']['b1'] = $b1;
        $state['bombs']['b2'] = $b2;

        // Detonating b1 should trigger b2 via chain reaction
        $detRes = $this->engine->detonateBomb($state, 'b1');

        $this->assertTrue($detRes['success']);
        $this->assertEmpty($state['bombs'], 'Both bombs should have exploded');
    }
}
