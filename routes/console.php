<?php

use App\Models\Room;
use App\Services\RoomService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('arena:tick {--once : Advance active rooms once} {--restart : Release the current worker lease for a graceful replacement}', function (RoomService $rooms): void {
    $lease = Cache::lock('arena_simulation_worker', 10);
    if ($this->option('restart')) {
        $lease->forceRelease();

        return;
    }
    if (! $this->option('once') && ! $lease->get()) {
        $this->error('Ya existe un proceso de simulacion activo.');

        return;
    }
    $lastRefresh = microtime(true);
    try {
        do {
            if (! $this->option('once') && microtime(true) - $lastRefresh > 2) {
                if (! $lease->refresh()) {
                    return;
                }
                $lastRefresh = microtime(true);
            }
            $started = hrtime(true);
            foreach (Room::where('status', Room::STATE_PLAYING)->pluck('code') as $code) {
                $rooms->tickRoom($code);
            }
            if ($this->option('once')) {
                return;
            }
            $remaining = (int) (1_000_000 / 30 - (hrtime(true) - $started) / 1000);
            if ($remaining > 0) {
                usleep($remaining);
            }
        } while (true);
    } finally {
        if (! $this->option('once')) {
            $lease->release();
        }
    }
})->purpose('Advance authoritative arena timers and configured movement at 20-30 Hz');
