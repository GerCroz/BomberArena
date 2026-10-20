<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RemoteStyleUpdate implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $newText;

    public string $newStyle;

    public function __construct(string $newText, string $newStyle = '')
    {
        $this->newText = $newText;
        $this->newStyle = $newStyle;
    }

    /**
     * Canal PÚBLICO (no requiere auth)
     */
    public function broadcastOn(): array
    {
        return [new Channel('style-channel')];
    }

    /**
     * (Opcional) nombre del evento en el frontend
     */
    public function broadcastAs(): string
    {
        return 'RemoteStyleUpdate';
    }
}
