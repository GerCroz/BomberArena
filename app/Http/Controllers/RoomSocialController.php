<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\CharacterCatalog;
use App\Services\PlayerIdentityService;
use App\Services\RoomSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class RoomSocialController extends Controller
{
    public function __construct(protected RoomSocialService $social) {}

    public function index(): JsonResponse
    {
        if (Cache::add('arena_social_sweep', true, 10)) {
            $this->social->sweep();
        }

        return response()->json(['rooms' => $this->social->publicRooms()]);
    }

    public function character(Request $request, string $code): JsonResponse
    {
        $player = $this->member($request, $code);
        $data = $request->validate(['character_id' => ['required', Rule::in(CharacterCatalog::ids())]]);
        $room = Room::where('code', strtoupper($code))->firstOrFail();

        return $this->result($this->social->selectCharacter($room, $player, $data['character_id']));
    }

    public function presence(Request $request, string $code): JsonResponse
    {
        return $this->result($this->social->heartbeat($code, $this->member($request, $code)));
    }

    public function leave(Request $request, string $code): JsonResponse
    {
        return $this->result($this->social->leave($code, $this->member($request, $code)));
    }

    public function requestRematch(Request $request, string $code): JsonResponse
    {
        $player = $this->member($request, $code);
        $data = $request->validate(['round_id' => ['required', 'string', 'max:64']]);

        return $this->result($this->social->requestRematch($code, $player, $data['round_id']));
    }

    public function respond(Request $request, string $code): JsonResponse
    {
        $player = $this->member($request, $code);
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'round_id' => ['required', 'string', 'max:64'], 'accept' => ['required', 'boolean']]);

        return $this->result($this->social->respond($code, $player, $data['request_id'], $data['round_id'], $data['accept']));
    }

    public function authorizeChannel(Request $request): JsonResponse
    {
        $data = $request->validate(['channel_name' => ['required', 'string', 'regex:/^private-arena-social\\.[A-Z0-9]{6}$/'], 'socket_id' => ['required', 'string', 'regex:/^\\d+\\.\\d+$/']]);
        $code = substr($data['channel_name'], strlen('private-arena-social.'));
        $this->member($request, $code);

        return response()->json(Broadcast::connection('reverb')->validAuthenticationResponse($request, []));
    }

    private function member(Request $request, string $code): string
    {
        $player = $request->cookie(PlayerIdentityService::COOKIE_NAME);
        abort_unless(is_string($player) && Room::where('code', strtoupper($code))->whereHas('players', fn ($query) => $query->where('player_id', $player))->exists(), 403, 'No perteneces a esta sala.');

        return $player;
    }

    private function result(array $result): JsonResponse
    {
        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
