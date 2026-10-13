<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\GameRulesService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create or update Default Admin User
        $adminEmail = env('BOMBER_ADMIN_EMAIL', 'admin@bomberarena.com');
        $adminPassword = env('BOMBER_ADMIN_PASSWORD', 'admin1234');

        User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Administrador de Reglas',
                'password' => Hash::make($adminPassword),
                'is_admin' => true,
            ]
        );

        // 2. Initialize default game rules if not present
        app(GameRulesService::class)->getActiveRules();
    }
}
