<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\ArenaInputService;
use App\Services\ArenaStateStore;
use App\Services\CharacterCatalog;
use App\Services\GameRulesService;
use App\Services\OrderedInputBuffer;
use App\Services\PlayerIdentityService;
use App\Services\RoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
        $playerId = $this->browserPlayerId($request);
        $nickname = $this->identity->getNickname($request);

        $characters = CharacterCatalog::CHARACTERS;
        $lobbyRules = app(GameRulesService::class)->getActiveRules();

        return response()
            ->view('bomber.lobby', compact('playerId', 'nickname', 'characters', 'lobbyRules'))
            ->cookie(PlayerIdentityService::COOKIE_NAME, $playerId, 60 * 24 * 7)
            ->cookie(PlayerIdentityService::NICKNAME_COOKIE, $nickname, 60 * 24 * 7);
    }

    /**
     * Show the game arena page.
     */
    public function arena(Request $request, string $code)
    {
        $room = Room::with('players')->where('code', strtoupper($code))->firstOrFail();
        $storedState = ArenaStateStore::get($room->code) ?? $room->game_state;
        $room->game_state = $storedState ? OrderedInputBuffer::snapshot($storedState) : null;
        $playerId = $this->browserPlayerId($request);
        $nickname = $this->identity->getNickname($request);
        $ownsIdentity = $request->cookie(PlayerIdentityService::COOKIE_NAME) === $playerId;
        $inputToken = $ownsIdentity && $room->players->contains('player_id', $playerId) ? ArenaInputService::token($room->code, $playerId) : null;

        return response()
            ->view('bomber.arena', compact('room', 'playerId', 'nickname', 'inputToken'))
            ->cookie(PlayerIdentityService::COOKIE_NAME, $playerId, 60 * 24 * 7)
            ->cookie(PlayerIdentityService::NICKNAME_COOKIE, $nickname, 60 * 24 * 7);
    }

    /**
     * API: Create room.
     */
    public function createRoom(Request $request): JsonResponse
    {
        $options = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'is_private' => ['nullable', 'boolean'],
            'max_players' => ['nullable', 'integer', 'between:2,4'],
            'character_id' => ['nullable', Rule::in(CharacterCatalog::ids())],
        ]);
        $playerId = $this->browserPlayerId($request);
        $nickname = $this->identity->getNickname($request, 'Comandante');

        $room = $this->roomService->createRoom($playerId, $nickname, $options);

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
            'character_id' => ['nullable', Rule::in(CharacterCatalog::ids())],
        ]);

        $playerId = $this->browserPlayerId($request);
        $nickname = $this->identity->getNickname($request, 'Piloto_'.rand(100, 999));

        $result = $this->roomService->joinRoom($request->code, $playerId, $nickname, $request->input('character_id'));

        if (! $result['success']) {
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
        if (! $room) {
            return response()->json(['error' => 'Sala no encontrada'], 404);
        }

        $cleanCode = strtoupper($code);
        $activeState = ArenaStateStore::get($cleanCode) ?? $room->game_state;
        if ($room->game_state) {
            $room->game_state = OrderedInputBuffer::snapshot($room->game_state);
        }

        return response()->json([
            'room' => $room,
            'players' => $room->players,
            'game_state' => $activeState ? OrderedInputBuffer::snapshot($activeState) : null,
            'server_time' => microtime(true),
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
     * API: Ordered batch of player actions (moves + bombs) in a single request.
     */
    public function actions(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'round_id' => ['nullable', 'string', 'max:64'],
            'sequence' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'actions' => ['required', 'array', 'min:1', 'max:40'],
            'actions.*.type' => ['required', 'string', 'in:move,walk,stop,bomb'],
            'actions.*.direction' => ['nullable', 'string', 'in:up,down,left,right'],
            'actions.*.temp_id' => ['nullable', 'string'],
        ]);

        $playerId = $this->identity->getOrCreatePlayerId($request);
        $result = $this->roomService->handleActions($code, $playerId, $validated['actions'], $validated['round_id'] ?? null, $validated['sequence'] ?? null);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * API: Advance server room simulation tick.
     */
    public function input(Request $request, string $code): JsonResponse
    {
        $input = $request->validate([
            'round_id' => ['required', 'string', 'max:64'],
            'sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'direction' => ['nullable', 'string', 'in:up,down,left,right'],
            'sampled_at' => ['required', 'numeric'],
            'frames' => ['sometimes', 'array', 'min:1', 'max:24'],
        ]);
        $playerId = $this->identity->getOrCreatePlayerId($request);
        $result = isset($input['frames'])
            ? $this->roomService->updateOrderedInput($code, $playerId, $input['round_id'], $input['frames'])
            : $this->roomService->updateInput($code, $playerId, $input['round_id'], (int) $input['sequence'], $input['direction'] ?? null, (float) $input['sampled_at']);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function tick(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate(['round_id' => ['nullable', 'string', 'max:64']]);
        $result = $this->roomService->tickRoom($code, $validated['round_id'] ?? null, $this->identity->getOrCreatePlayerId($request));

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * API: Trigger Detonate.
     */
    public function detonate(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'bomb_id' => ['required', 'string'],
            'round_id' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->roomService->detonateBomb($code, $validated['bomb_id'], $validated['round_id'] ?? null);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    private function browserPlayerId(Request $request): string
    {
        $player = $request->cookie(PlayerIdentityService::COOKIE_NAME);
        if (is_string($player) && preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $player)) {
            return $player;
        }

        return 'p_'.Str::random(24);
    }
}
