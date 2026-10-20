<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlayerIdentityService
{
    public const COOKIE_NAME = 'bomber_player_id';

    public const NICKNAME_COOKIE = 'bomber_player_nickname';

    /**
     * Get or generate a secure player ID for the request.
     */
    public function getOrCreatePlayerId(Request $request): string
    {
        $playerId = $request->header('X-Player-ID')
            ?? $request->input('player_id')
            ?? $request->cookie(self::COOKIE_NAME);

        if (! empty($playerId) && is_string($playerId) && preg_match('/^[a-zA-Z0-9_\-]{8,64}$/', $playerId)) {
            return $playerId;
        }

        return 'p_'.Str::random(24);
    }

    /**
     * Get player nickname from request or session.
     */
    public function getNickname(Request $request, ?string $fallback = null): string
    {
        $nick = $request->input('nickname')
            ?? $request->cookie(self::NICKNAME_COOKIE)
            ?? $fallback
            ?? ('Piloto_'.rand(100, 999));

        $clean = trim(strip_tags((string) $nick));

        return substr($clean, 0, 20) ?: ('Piloto_'.rand(100, 999));
    }
}
