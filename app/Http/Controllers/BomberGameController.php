<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\RoomService;
use App\Services\PlayerIdentityService;
use App\Models\Room;

class BomberGameController extends Controller
{
    protected RoomService $roomService;
    protected PlayerIdentityService $identity;

    public function __construct(RoomService $roomService, PlayerIdentityService $identity)
    {
        $this->roomService = $roomService;
        $this->identity = $identity;
    }

    /**
     * Show main landing / lobby page.
     */
    public function lobby(Request $request)
    {
        $playerId = $this->identity->getOrCreatePlayerId($request);
        $nickname = $this->identity->getNickname($request);

        return response()
            ->view('bomber.lobby', compact('playerId', 'nickname'))
            ->cookie(PlayerIdentityService::COOKIE_NAME, $playerId, 60 * 24 * 7)
            ->cookie(PlayerIdentityService::NICKNAME_COOKIE, $nickname, 60 * 24 * 7);
    }

    /**
     * Show the game arena page.
     */
    public function arena(Request $request, string $code)
    {
        $room = Room::with('players')->where('code', strtoupper($code))->firstOrFail();
        $playerId = $this->identity->getOrCreatePlayerId($request);
        $nickname = $this->identity->getNickname($request);

        return response()
            ->view('bomber.arena', compact('room', 'playerId', 'nickname'))
            ->cookie(PlayerIdentityService::COOKIE_NAME, $playerId, 60 * 24 * 7)
            ->cookie(PlayerIdentityService::NICKNAME_COOKIE, $nickname, 60 * 24 * 7);
    }

    /**
     * API: Create room.
     */
    public function createRoom(Request $request): JsonResponse
    {
        $playerId = $this->identity->getOrCreatePlayerId($request);
        $nickname = $this->identity->getNickname($request, 'Comandante');

        $room = $this->roomService->createRoom($playerId, $nickname);

        return response()->json([
            'success' => true,
            'room' => $room,
            'player_id' => $playerId,
            'redirect' => route('arena', ['code' => $room->code]),
        ])->cookie(PlayerIdentityService::COOKIE_NAME, $playerId, 60 * 24 * 7)
          ->cookie(PlayerIdentityService::NICKNAME_COOKIE, $nickname, 60 * 24 * 7);
    }

    /**
     * API: Join room.
     */
    public function joinRoom(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:8'],
        ]);

        $playerId = $this->identity->getOrCreatePlayerId($request);
        $nickname = $this->identity->getNickname($request, 'Piloto_' . rand(100, 999));

        $result = $this->roomService->joinRoom($request->code, $playerId, $nickname);

        if (!$result['success']) {
            return response()->json($result, 422);
        }

        return response()->json([
            'success' => true,
            'room' => $result['room'],
            'player' => $result['player'],
            'redirect' => route('arena', ['code' => $result['room']->code]),
        ])->cookie(PlayerIdentityService::COOKIE_NAME, $playerId, 60 * 24 * 7)
          ->cookie(PlayerIdentityService::NICKNAME_COOKIE, $nickname, 60 * 24 * 7);
    }

    /**
     * API: Get room state snapshot.
     */
    public function getState(string $code): JsonResponse
    {
        $room = Room::with('players')->where('code', strtoupper($code))->first();
        if (!$room) {
            return response()->json(['error' => 'Sala no encontrada'], 404);
        }

        $cleanCode = strtoupper($code);
        $activeState = \Illuminate\Support\Facades\Cache::get("arena_state_{$cleanCode}") ?? $room->game_state;

        return response()->json([
            'room' => $room,
            'players' => $room->players,
            'game_state' => $activeState,
        ]);
    }

    /**
     * API: Toggle ready.
     */
    public function toggleReady(Request $request, string $code): JsonResponse
    {
        $playerId = $this->identity->getOrCreatePlayerId($request);
        $result = $this->roomService->toggleReady($code, $playerId);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * API: Start game.
     */
    public function start(Request $request, string $code): JsonResponse
    {
        $playerId = $this->identity->getOrCreatePlayerId($request);
        $result = $this->roomService->startGame($code, $playerId);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * API: Command Move.
     */
    public function move(Request $request, string $code): JsonResponse
    {
        $request->validate([
            'direction' => ['required', 'string', 'in:up,down,left,right'],
        ]);

        $playerId = $this->identity->getOrCreatePlayerId($request);
        $result = $this->roomService->handleMove($code, $playerId, $request->direction);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * API: Command Bomb.
     */
    public function bomb(Request $request, string $code): JsonResponse
    {
        $playerId = $this->identity->getOrCreatePlayerId($request);
        $result = $this->roomService->placeBomb($code, $playerId);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * API: Trigger Detonate.
     */
    public function detonate(Request $request, string $code): JsonResponse
    {
        $request->validate([
            'bomb_id' => ['required', 'string'],
        ]);

        $result = $this->roomService->detonateBomb($code, $request->bomb_id);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * API: Request Rematch.
     */
    public function rematch(Request $request, string $code): JsonResponse
    {
        $playerId = $this->identity->getOrCreatePlayerId($request);
        $result = $this->roomService->requestRematch($code, $playerId);

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
