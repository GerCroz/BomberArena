<?php

use App\Models\Room;
use App\Services\ArenaSnapshotPublisher;
use App\Services\ArenaStateStore;
use App\Services\RoomService;
use App\Services\RoomSocialService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('arena:rooms', function (RoomSocialService $social): void {
    $social->sweep();
})->purpose('Expire abandoned rooms and rematch requests');

Schedule::command('arena:rooms')->everyMinute()->withoutOverlapping();

Artisan::command('arena:publish {--once : Publish changed room snapshots once} {--restart : Release the publisher lease for replacement}', function (ArenaSnapshotPublisher $publisher): void {
    $lease = ArenaStateStore::cache()->lock('arena_publication_worker', 10);
    if ($this->option('restart')) {
        $lease->forceRelease();

        return;
    }
    if (! $this->option('once') && ! $lease->get()) {
        $this->error('Ya existe un publicador de estados activo.');

        return;
    }
    $lastRefresh = microtime(true);
    $lastRooms = 0;
    $codes = [];
    try {
        do {
            $started = hrtime(true);
            if (! $this->option('once') && microtime(true) - $lastRefresh > 2) {
                if (! $lease->refresh()) {
                    return;
                }
                $lastRefresh = microtime(true);
            }
            if (microtime(true) - $lastRooms >= 0.5) {
                $codes = Room::where('status', Room::STATE_PLAYING)->orWhere(fn (Builder $query) => $query->where('status', Room::STATE_FINISHED)->where('updated_at', '>=', now()->subMinutes(5)))->pluck('code')->all();
                $lastRooms = microtime(true);
            }
            foreach ($codes as $code) {
                $state = ArenaStateStore::get($code);
                if ($state) {
                    try {
                        $publisher->publish($code, $state['round_id'], $state);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
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
})->purpose('Publish only the newest authoritative snapshots without blocking simulation');

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
