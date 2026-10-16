<?php

namespace App\Providers;

use App\Services\ArenaInputService;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\Events\MessageReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(MessageReceived::class, [ArenaInputService::class, 'receive']);
        DevCommands::artisan('arena:tick --no-interaction', 'arena');
        DevCommands::artisan('arena:publish --no-interaction', 'arena-publish');
        DevCommands::artisan('reverb:start --no-interaction', 'reverb');
    }
}
