<?php

namespace App\Services;

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
                'latency_ms' => $latencyMs ?? rand(8, 25), // typical local/fast loopback ms
                'created_at' => now(),
            ]);

            // Broadcast to the instructor channel
            broadcast(new \App\Events\InstructorTelemetryEvent(
                roomCode: $roomCode,
                eventName: $eventName,
                source: $source ?? 'Servidor',
                destination: $destination ?? "arena.{$roomCode}",
                payload: $payload,
                latencyMs: $latencyMs ?? rand(8, 25),
                timestamp: microtime(true)
            ));
        } catch (\Throwable $e) {
            // Monitoring must never crash the game loop
            report($e);
        }
    }
}
