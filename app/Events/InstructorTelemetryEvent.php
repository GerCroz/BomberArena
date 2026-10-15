<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstructorTelemetryEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;

    public string $eventName;

    public string $source;

    public string $destination;

    public array $payload;

    public ?int $latencyMs;

    public float $timestamp;

    public function __construct(
        string $roomCode,
        string $eventName,
        string $source,
        string $destination,
        array $payload,
        ?int $latencyMs,
        float $timestamp
    ) {
        $this->roomCode = $roomCode;
        $this->eventName = $eventName;
        $this->source = $source;
        $this->destination = $destination;
        $this->payload = $payload;
        $this->latencyMs = $latencyMs;
        $this->timestamp = $timestamp;
    }

    public function broadcastOn(): array
    {
        return [new Channel('instructor-monitor')];
    }

    public function broadcastAs(): string
    {
        return 'TelemetryEvent';
    }
}
