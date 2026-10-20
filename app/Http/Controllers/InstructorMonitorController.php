<?php

namespace App\Http\Controllers;

use App\Models\GameTelemetryLog;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorMonitorController extends Controller
{
    /**
     * Show instructor dashboard view.
     */
    public function dashboard(Request $request)
    {
        $rooms = Room::with('players')->orderByDesc('updated_at')->take(10)->get();
        $recentLogs = GameTelemetryLog::orderByDesc('id')->take(50)->get();
        $totalLogsCount = GameTelemetryLog::count();

        return view('bomber.instructor', compact('rooms', 'recentLogs', 'totalLogsCount'));
    }

    /**
     * API: Get telemetry events and active rooms status.
     */
    public function getTelemetry(Request $request): JsonResponse
    {
        $sinceId = (int) $request->input('since_id', 0);
        $roomCode = $request->input('room_code');

        $query = GameTelemetryLog::orderBy('id', 'asc');

        if ($sinceId > 0) {
            $query->where('id', '>', $sinceId);
        }

        if (! empty($roomCode)) {
            $query->where('room_code', strtoupper($roomCode));
        }

        $logs = $query->take(100)->get();
        $activeRooms = Room::with('players')->where('status', 'playing')->orWhere('status', 'waiting')->get();

        return response()->json([
            'logs' => $logs,
            'active_rooms' => $activeRooms,
            'stats' => [
                'total_events' => GameTelemetryLog::count(),
                'avg_latency' => round(GameTelemetryLog::avg('latency_ms') ?? 15, 1),
            ],
        ]);
    }

    /**
     * API: Clear logs.
     */
    public function clearLogs(): JsonResponse
    {
        GameTelemetryLog::truncate();

        return response()->json(['success' => true]);
    }
}
