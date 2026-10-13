<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add is_admin to users if not present
        if (! Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_admin')->default(false)->after('password');
            });
        }

        // 2. Game rule settings table
        Schema::create('game_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->json('settings');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 100)->nullable();
            $table->string('change_summary', 255)->nullable();
            $table->timestamps();
        });

        // 3. Match / State transition audit logs
        Schema::create('game_match_audits', function (Blueprint $table) {
            $table->id();
            $table->string('room_code', 8)->index();
            $table->string('from_state', 32);
            $table->string('to_state', 32)->index();
            $table->string('reason', 64)->index(); // e.g. LAST_PLAYER_ALIVE, TIME_EXPIRED, ALL_PLAYERS_ELIMINATED, ADMIN_CANCELLED
            $table->json('details')->nullable(); // alive players, remaining time, winner, stats
            $table->unsignedSmallInteger('alive_count')->default(0);
            $table->unsignedSmallInteger('remaining_seconds')->default(0);
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        // 4. Enhance rooms table for audit, structured finish reason, rules snapshot & stats
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('finish_reason', 64)->nullable()->after('winner_player_id');
            $table->json('rules_snapshot')->nullable()->after('finish_reason');
            $table->unsignedInteger('rules_version')->nullable()->after('rules_snapshot');
            $table->unsignedInteger('total_bombs_detonated')->default(0)->after('rules_version');
            $table->unsignedInteger('total_blocks_destroyed')->default(0)->after('total_bombs_detonated');
            $table->unsignedInteger('total_eliminations')->default(0)->after('total_blocks_destroyed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'finish_reason',
                'rules_snapshot',
                'rules_version',
                'total_bombs_detonated',
                'total_blocks_destroyed',
                'total_eliminations',
            ]);
        });

        Schema::dropIfExists('game_match_audits');
        Schema::dropIfExists('game_rules');

        if (Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_admin');
            });
        }
    }
};
