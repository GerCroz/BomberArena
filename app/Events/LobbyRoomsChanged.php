<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LobbyRoomsChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public string $type, public array $data = []) {}

    public function broadcastOn(): array
    {
        return [new Channel('arena-lobby')];
    }

    public function broadcastAs(): string
    {
        return 'LobbyRoomsChanged';
    }
}
