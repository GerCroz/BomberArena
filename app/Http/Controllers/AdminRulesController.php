<?php

namespace App\Http\Controllers;

use App\Models\GameMatchAudit;
use App\Models\GameRule;
use App\Models\Room;
use App\Services\GameRulesService;
use App\Services\RoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class AdminRulesController extends Controller
{
    public function __construct(
        protected GameRulesService $rulesService,
        protected RoomService $roomService
    ) {}

    /**
     * Show admin login view.
     */
    public function showLogin()
    {
        if (Auth::check() && Auth::user()->is_admin) {
            return redirect()->route('admin.rules');
        }

        return view('bomber.admin-login');
    }

    /**
     * Handle admin login authentication.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            if (! Auth::user()->is_admin) {
                Auth::logout();

                return back()->withErrors(['email' => 'El usuario no posee permisos de administrador.']);
            }

            return redirect()->intended(route('admin.rules'));
        }

        return back()->withErrors(['email' => 'Las credenciales no coinciden con nuestros registros.']);
    }

    /**
     * Handle admin logout.
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /**
     * Show Rules Configuration & Active Matches Dashboard.
     */
    public function rulesDashboard()
    {
        $activeRecord = $this->rulesService->getActiveRecord();
        $activeRules = $this->rulesService->getActiveRules();
        $history = GameRule::with('createdBy')->orderByDesc('version')->take(15)->get();

        $activeRooms = Room::with('players')
            ->whereIn('status', [Room::STATE_PLAYING, Room::STATE_WAITING, Room::STATE_READY])
            ->orderByDesc('updated_at')
            ->get();

        $finishedRooms = Room::with('players')
            ->whereIn('status', [Room::STATE_FINISHED, Room::STATE_CANCELLED])
            ->orderByDesc('finished_at')
            ->take(20)
            ->get();

        $recentAudits = GameMatchAudit::orderByDesc('id')->take(30)->get();

        return view('bomber.admin-rules', compact(
            'activeRecord',
            'activeRules',
            'history',
            'activeRooms',
            'finishedRooms',
            'recentAudits'
        ));
    }

    /**
     * Update and version game rules.
     */
    public function updateRules(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'round_duration_seconds' => ['required', 'integer', 'min:30', 'max:1200'],
            'min_players' => ['required', 'integer', 'min:2', 'max:4'],
            'max_players' => ['required', 'integer', 'min:2', 'max:4'],
            'countdown_seconds' => ['required', 'integer', 'min:0', 'max:10'],
            'end_on_last_survivor' => ['nullable', 'boolean'],
            'draw_on_simultaneous_elimination' => ['nullable', 'boolean'],
            'timeout_result' => ['required', 'string', 'in:most_bombs,most_kills'],
            'allow_rematch' => ['nullable', 'boolean'],

            'bomb_fuse_ms' => ['required', 'integer', 'min:500', 'max:10000'],
            'bomb_initial_range' => ['required', 'integer', 'min:1', 'max:10'],
            'bomb_initial_simultaneous' => ['required', 'integer', 'min:1', 'max:5'],
            'allow_chain_reaction' => ['nullable', 'boolean'],
            'explosion_visual_duration_ms' => ['required', 'integer', 'min:200', 'max:2000'],
            'self_damage' => ['nullable', 'boolean'],
            'friendly_fire' => ['nullable', 'boolean'],

            'player_initial_speed' => ['required', 'integer', 'min:1', 'max:3'],
            'player_collisions' => ['nullable', 'boolean'],
            'reconnection_enabled' => ['nullable', 'boolean'],
            'reconnect_grace_seconds' => ['required', 'integer', 'min:0', 'max:120'],
            'eliminate_on_disconnect' => ['nullable', 'boolean'],
            'dead_players_block_movement' => ['nullable', 'boolean'],

            'block_density_percent' => ['required', 'integer', 'min:10', 'max:90'],
            'powerups_enabled' => ['nullable', 'boolean'],
            'powerup_drop_chance_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'random_map_generation' => ['nullable', 'boolean'],

            'change_summary' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = [
            'round_duration_seconds' => (int) $validated['round_duration_seconds'],
            'min_players' => (int) $validated['min_players'],
            'max_players' => (int) $validated['max_players'],
            'countdown_seconds' => (int) $validated['countdown_seconds'],
            'end_on_last_survivor' => $request->boolean('end_on_last_survivor'),
            'draw_on_simultaneous_elimination' => $request->boolean('draw_on_simultaneous_elimination'),
            'timeout_result' => $validated['timeout_result'],
            'allow_rematch' => $request->boolean('allow_rematch'),

            'bomb_fuse_ms' => (int) $validated['bomb_fuse_ms'],
            'bomb_initial_range' => (int) $validated['bomb_initial_range'],
            'bomb_initial_simultaneous' => (int) $validated['bomb_initial_simultaneous'],
            'allow_chain_reaction' => $request->boolean('allow_chain_reaction'),
            'explosion_visual_duration_ms' => (int) $validated['explosion_visual_duration_ms'],
            'self_damage' => $request->boolean('self_damage'),
            'friendly_fire' => $request->boolean('friendly_fire'),

            'player_initial_speed' => (int) $validated['player_initial_speed'],
            'player_collisions' => $request->boolean('player_collisions'),
            'reconnection_enabled' => $request->boolean('reconnection_enabled'),
            'reconnect_grace_seconds' => (int) $validated['reconnect_grace_seconds'],
            'eliminate_on_disconnect' => $request->boolean('eliminate_on_disconnect'),
            'dead_players_block_movement' => $request->boolean('dead_players_block_movement'),

            'board_cols' => 13,
            'board_rows' => 11,
            'block_density_percent' => (int) $validated['block_density_percent'],
            'powerups_enabled' => $request->boolean('powerups_enabled'),
            'powerup_drop_chance_percent' => (int) $validated['powerup_drop_chance_percent'],
            'random_map_generation' => $request->boolean('random_map_generation'),
        ];

        $rule = $this->rulesService->saveNewRules(
            $settings,
            $user?->id,
            $user?->name ?: 'Administrador',
            $request->input('change_summary') ?: 'Actualización de parámetros desde panel web'
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'version' => $rule->version,
                'settings' => $rule->settings,
            ]);
        }

        return redirect()->route('admin.rules')->with('status', "Reglas actualizadas exitosamente a la Versión {$rule->version}!");
    }

    /**
     * API: Get active matches and audits for live administrative panel.
     */
    public function getActiveMatches(Request $request): JsonResponse
    {
        $rooms = Room::with('players')
            ->orderByDesc('updated_at')
            ->take(50)
            ->get()
            ->map(function ($r) {
                $state = Cache::get("arena_state_{$r->code}") ?? $r->game_state;
                $alivePlayers = 0;
                $activeBombs = 0;
                $elapsedSeconds = 0;
                $remainingSeconds = $r->round_duration_seconds;

                if (is_array($state) && isset($state['players'])) {
                    $alivePlayers = count(array_filter($state['players'], fn ($p) => ! empty($p['alive'])));
                    $activeBombs = count($state['bombs'] ?? []);
                    if (! empty($state['started_at'])) {
                        $elapsedSeconds = max(0, (int) (microtime(true) - $state['started_at']));
                        $duration = (int) ($state['duration_seconds'] ?? $r->round_duration_seconds);
                        $remainingSeconds = max(0, $duration - $elapsedSeconds);
                    }
                }

                return [
                    'id' => $r->id,
                    'code' => $r->code,
                    'status' => $r->status,
                    'max_players' => $r->max_players,
                    'player_count' => $r->players->count(),
                    'alive_players' => $alivePlayers,
                    'active_bombs' => $activeBombs,
                    'elapsed_seconds' => $elapsedSeconds,
                    'remaining_seconds' => $remainingSeconds,
                    'rules_version' => $r->rules_version,
                    'finish_reason' => $r->finish_reason,
                    'winner_player_id' => $r->winner_player_id,
                    'total_bombs_detonated' => $r->total_bombs_detonated,
                    'total_blocks_destroyed' => $r->total_blocks_destroyed,
                    'total_eliminations' => $r->total_eliminations,
                    'players' => $r->players,
                    'started_at' => $r->started_at?->toIso8601String(),
                    'finished_at' => $r->finished_at?->toIso8601String(),
                ];
            });

        $audits = GameMatchAudit::orderByDesc('id')->take(50)->get();

        return response()->json([
            'rooms' => $rooms,
            'audits' => $audits,
        ]);
    }

    /**
     * API / Form: Administratively cancel an active room.
     */
    public function cancelMatch(Request $request, string $code)
    {
        $adminName = Auth::user()?->name ?: 'Admin';
        $result = $this->roomService->cancelMatch($code, $adminName, $request->input('reason'));

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return redirect()->route('admin.rules')->with('status', "La sala {$code} ha sido cancelada.");
    }
}
