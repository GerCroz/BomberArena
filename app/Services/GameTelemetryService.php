<?php

namespace App\Services;

use App\Events\InstructorTelemetryEvent;
use App\Models\GameTelemetryLog;
use Illuminate\Support\Facades\Event;

class GameTelemetryService
{
    /**
     * Record and dispatch telemetry event for teacher monitoring and audit.
     */
    public function recordEvent(
        string $roomCode,
        string $eventName,
        ?string $source = null,
        ?string $destination = null,
        array $payload = [],
        ?int $latencyMs = null
    ): void {
        try {
            // Log to database asynchronously / lightweight
            GameTelemetryLog::create([
                'room_code' => $roomCode,
                'event_name' => $eventName,
                'source' => $source ?? 'Servidor',
                'destination' => $destination ?? "arena.{$roomCode}",
                'payload' => $payload,
                'latency_ms' => $latencyMs,
                'created_at' => now(),
            ]);

            // Broadcast to the instructor channel
            broadcast(new InstructorTelemetryEvent(
                roomCode: $roomCode,
                eventName: $eventName,
                source: $source ?? 'Servidor',
                destination: $destination ?? "arena.{$roomCode}",
                payload: $payload,
                latencyMs: $latencyMs,
                timestamp: microtime(true)
            ));
        } catch (\Throwable $e) {
            // Monitoring must never crash the game loop
            report($e);
        }
    }
}
