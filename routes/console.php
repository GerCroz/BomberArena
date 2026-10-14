<?php

use App\Models\Room;
use App\Services\BomberGameEngine;
use App\Services\RoomService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('arena:tick {--once : Advance active rooms once}', function (RoomService $rooms): void {
    do {
        $started = hrtime(true);
        foreach (Room::where('status', Room::STATE_PLAYING)->pluck('code') as $code) {
            $rooms->tickRoom($code);
        }
        if ($this->option('once')) {
            return;
        }
        $remaining = (int) (BomberGameEngine::TICK_SECONDS * 1_000_000 - (hrtime(true) - $started) / 1000);
        if ($remaining > 0) {
            usleep($remaining);
        }
    } while (true);
})->purpose('Advance authoritative arena timers and movement at 20 Hz');
