<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomSocialEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public string $roomCode, public string $type, public array $data = []) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('arena-social.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'RoomSocialEvent';
    }
}
