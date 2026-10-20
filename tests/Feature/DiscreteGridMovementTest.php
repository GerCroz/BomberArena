<?php

namespace Tests\Feature;

use App\Services\BomberGameEngine;
use App\Services\OrderedInputBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscreteGridMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_discrete_grid_frame_validation_and_fields(): void
    {
        $frame = [
            'sequence' => 1,
            'duration' => 0.05,
            'direction' => 'down',
            'sampled_at' => 100.0,
            'from_x' => 1,
            'from_y' => 1,
            'to_x' => 1,
            'to_y' => 2,
        ];

        $valid = OrderedInputBuffer::valid([$frame]);
        $this->assertTrue($valid);
    }

    public function test_single_discrete_step_advances_integer_coordinates(): void
    {
        $engine = new BomberGameEngine;
        $state = $this->createMockState();
        $time = $state['started_at'] + 0.1;

        // Player p0 at (1,1). Move down to (1,2)
        $frame = [
            'sequence' => 1,
            'duration' => 0.05,
            'direction' => 'down',
            'sampled_at' => $time,
            'from_x' => 1,
            'from_y' => 1,
            'to_x' => 1,
            'to_y' => 2,
        ];

        OrderedInputBuffer::merge($state, 'p0', [$frame], $time);
        $engine->advanceMovement($state, $time);

        $this->assertSame(1, $state['players']['p0']['x']);
        $this->assertSame(2, $state['players']['p0']['y']);
        $this->assertSame(1, (int) round($state['players']['p0']['position_x']));
        $this->assertSame(2, (int) round($state['players']['p0']['position_y']));
        $this->assertSame(1, $state['input_ack']['p0']);
    }

    public function test_obstacle_wall_and_block_blocks_discrete_movement(): void
    {
        $engine = new BomberGameEngine;
        $state = $this->createMockState();
        $time = $state['started_at'] + 0.1;

        // Player p0 is at (1,1). (1,0) is outer wall (tile = 1).
        $frameUp = [
            'sequence' => 1,
            'duration' => 0.05,
            'direction' => 'up',
            'sampled_at' => $time,
            'from_x' => 1,
            'from_y' => 1,
            'to_x' => 1,
            'to_y' => 0,
        ];

        OrderedInputBuffer::merge($state, 'p0', [$frameUp], $time);
        $engine->advanceMovement($state, $time);

        // Movement must be rejected, keeping player at (1,1)
        $this->assertSame(1, $state['players']['p0']['x']);
        $this->assertSame(1, $state['players']['p0']['y']);

        // Next, try stepping into (2, 1) which is a destructible block (tile = 2)
        $frameRight = [
            'sequence' => 2,
            'duration' => 0.05,
            'direction' => 'right',
            'sampled_at' => $time + 0.1,
            'from_x' => 1,
            'from_y' => 1,
            'to_x' => 2,
            'to_y' => 1,
        ];

        OrderedInputBuffer::merge($state, 'p0', [$frameRight], $time + 0.1);
        $engine->advanceMovement($state, $time + 0.1);

        $this->assertSame(1, $state['players']['p0']['x']);
        $this->assertSame(1, $state['players']['p0']['y']);
    }

    public function test_simultaneous_discrete_movement_for_three_and_four_players(): void
    {
        $engine = new BomberGameEngine;
        $state = $this->createMockState(4);
        $time = $state['started_at'] + 0.1;

        // p0 at (1,1) moves down to (1,2)
        // Spawn positions:
        // p0 at (1,1) moves down to (1,2)
        // p1 at (11,9) moves up to (11,8)
        // p2 at (11,1) moves down to (11,2)
        // p3 at (1,9) moves up to (1,8)
        $moves = [
            'p0' => ['dir' => 'down', 'from_x' => 1, 'from_y' => 1, 'to_x' => 1, 'to_y' => 2],
            'p1' => ['dir' => 'up', 'from_x' => 11, 'from_y' => 9, 'to_x' => 11, 'to_y' => 8],
            'p2' => ['dir' => 'down', 'from_x' => 11, 'from_y' => 1, 'to_x' => 11, 'to_y' => 2],
            'p3' => ['dir' => 'up', 'from_x' => 1, 'from_y' => 9, 'to_x' => 1, 'to_y' => 8],
        ];

        foreach ($moves as $id => $m) {
            $frame = [
                'sequence' => 1,
                'duration' => 0.05,
                'direction' => $m['dir'],
                'sampled_at' => $time,
                'from_x' => $m['from_x'],
                'from_y' => $m['from_y'],
                'to_x' => $m['to_x'],
                'to_y' => $m['to_y'],
            ];
            OrderedInputBuffer::merge($state, $id, [$frame], $time);
        }

        $engine->advanceMovement($state, $time);

        $this->assertSame(2, $state['players']['p0']['y']);
        $this->assertSame(8, $state['players']['p1']['y']);
        $this->assertSame(2, $state['players']['p2']['y']);
        $this->assertSame(8, $state['players']['p3']['y']);
    }

    public function test_players_can_cross_each_other_when_collisions_disabled(): void
    {
        $engine = new BomberGameEngine;
        $state = $this->createMockState(2);
        $state['rules']['player_collisions'] = false;
        $time = $state['started_at'] + 0.1;

        // Position p0 at (1,2) and p1 at (1,3)
        $state['players']['p0']['x'] = 1;
        $state['players']['p0']['y'] = 2;
        $state['players']['p0']['position_x'] = 1;
        $state['players']['p0']['position_y'] = 2;

        $state['players']['p1']['x'] = 1;
        $state['players']['p1']['y'] = 3;
        $state['players']['p1']['position_x'] = 1;
        $state['players']['p1']['position_y'] = 3;

        // p0 moves down to (1,3)
        $frameP0 = [
            'sequence' => 1,
            'duration' => 0.05,
            'direction' => 'down',
            'sampled_at' => $time,
            'from_x' => 1,
            'from_y' => 2,
            'to_x' => 1,
            'to_y' => 3,
        ];
        OrderedInputBuffer::merge($state, 'p0', [$frameP0], $time);
        $engine->advanceMovement($state, $time);

        $this->assertSame(3, $state['players']['p0']['y']);
    }

    public function test_bomb_pass_through_and_revocation(): void
    {
        $engine = new BomberGameEngine;
        $state = $this->createMockState();
        $time = $state['started_at'] + 0.1;

        // Plant a bomb at (1,1) owned by p0 with pass_through_players = ['p0']
        $state['bombs']['b1'] = [
            'id' => 'b1',
            'x' => 1,
            'y' => 1,
            'owner_id' => 'p0',
            'pass_through_players' => ['p0'],
            'planted_at' => $time,
            'explodes_at' => $time + 2.0,
            'range' => 2,
            'state' => 'active',
        ];

        // p0 steps off bomb to (1,2)
        $frame1 = [
            'sequence' => 1,
            'duration' => 0.05,
            'direction' => 'down',
            'sampled_at' => $time,
            'from_x' => 1,
            'from_y' => 1,
            'to_x' => 1,
            'to_y' => 2,
        ];
        OrderedInputBuffer::merge($state, 'p0', [$frame1], $time);
        $engine->advanceMovement($state, $time);

        $this->assertSame(2, $state['players']['p0']['y']);
        // Pass-through should be revoked now
        $this->assertNotContains('p0', $state['bombs']['b1']['pass_through_players']);

        // Now, p0 tries to step back onto (1,1) which now has a solid bomb without pass-through
        $frame2 = [
            'sequence' => 2,
            'duration' => 0.05,
            'direction' => 'up',
            'sampled_at' => $time + 0.1,
            'from_x' => 1,
            'from_y' => 2,
            'to_x' => 1,
            'to_y' => 1,
        ];
        OrderedInputBuffer::merge($state, 'p0', [$frame2], $time + 0.1);
        $engine->advanceMovement($state, $time + 0.1);

        // Movement must be blocked! Player remains at (1,2)
        $this->assertSame(2, $state['players']['p0']['y']);
    }

    public function test_bomb_placed_at_exact_logical_cell(): void
    {
        $engine = new BomberGameEngine;
        $state = $this->createMockState();
        $time = $state['started_at'] + 0.1;

        // Move p0 to (1,2)
        $state['players']['p0']['x'] = 1;
        $state['players']['p0']['y'] = 2;
        $state['players']['p0']['position_x'] = 1;
        $state['players']['p0']['position_y'] = 2;

        $res = $engine->handlePlaceBomb($state, 'p0', $time);
        $this->assertTrue($res['success']);

        $bomb = $res['bomb'];
        $this->assertSame(1, $bomb['x']);
        $this->assertSame(2, $bomb['y']);
        $this->assertSame('p0', $bomb['owner_id']);
    }

    public function test_stale_or_expired_sequence_rejection(): void
    {
        $engine = new BomberGameEngine;
        $state = $this->createMockState();
        $time = $state['started_at'] + 0.1;

        // Advance sequence to 10
        $state['input_ack']['p0'] = 10;
        $state['input_sequences']['p0'] = 10;
        $state['players']['p0']['x'] = 1;
        $state['players']['p0']['y'] = 2;
        $state['players']['p0']['position_x'] = 1;
        $state['players']['p0']['position_y'] = 2;

        // Outdated sequence 5
        $staleFrame = [
            'sequence' => 5,
            'duration' => 0.05,
            'direction' => 'up',
            'sampled_at' => $time,
            'from_x' => 1,
            'from_y' => 2,
            'to_x' => 1,
            'to_y' => 1,
        ];
        OrderedInputBuffer::merge($state, 'p0', [$staleFrame], $time);
        $engine->advanceMovement($state, $time);

        // Should be ignored; position remains (1,2)
        $this->assertSame(2, $state['players']['p0']['y']);
    }

    private function createMockState(int $playerCount = 2): array
    {
        $records = [];
        for ($i = 0; $i < $playerCount; $i++) {
            $records[] = ['player_id' => 'p'.$i, 'slot_index' => $i];
        }

        $engine = new BomberGameEngine;
        $state = $engine->createInitialState($records, 12345);

        // Ensure destructible block at (2,1) for testing obstacle collisions
        $state['grid'][1][2] = 2;

        return $state;
    }
}
