<?php

namespace App\Services;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class ArenaStateStore
{
    public static function cache(): Repository
    {
        return Cache::store(config('cache.arena_state_store') ?? config('cache.default'));
    }

    public static function get(string $code): ?array
    {
        $key = 'arena_state_'.strtoupper($code);

        return self::cache()->get($key) ?? Cache::get($key);
    }
}
