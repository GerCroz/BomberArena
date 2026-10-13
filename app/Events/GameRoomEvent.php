<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameRoomEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;

    public string $type;

    public array $data;

    public float $timestamp;

    public function __construct(string $roomCode, string $type, array $data = [])
    {
        $this->roomCode = $roomCode;
        $this->type = $type;
        $this->data = $data;
        $this->timestamp = microtime(true);
    }

    public function broadcastOn(): array
    {
        return [new Channel('arena.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'GameEvent';
    }
}
