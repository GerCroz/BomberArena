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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique()->index();
            $table->string('host_player_id', 64)->index();
            $table->string('status', 20)->default('waiting'); // waiting, countdown, playing, finished
            $table->unsignedTinyInteger('max_players')->default(4);
            $table->unsignedSmallInteger('round_duration_seconds')->default(180);
            $table->unsignedInteger('map_seed')->default(12345);
            $table->longText('game_state')->nullable(); // JSON snapshot of current game state
            $table->string('winner_player_id', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('room_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('player_id', 64)->index();
            $table->string('nickname', 32);
            $table->string('color', 16); // neon-blue, neon-pink, neon-yellow, neon-green
            $table->unsignedTinyInteger('slot_index')->default(0); // 0..3 spawn slot
            $table->boolean('is_host')->default(false);
            $table->boolean('is_ready')->default(false);
            $table->boolean('is_alive')->default(true);
            $table->unsignedSmallInteger('score')->default(0);
            $table->json('stats')->nullable(); // kills, bombs_placed, powerups_collected
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'player_id']);
        });

        Schema::create('game_telemetry_logs', function (Blueprint $table) {
            $table->id();
            $table->string('room_code', 8)->index();
            $table->string('event_name', 64)->index();
            $table->string('source', 64)->nullable(); // Player ID or 'Server'
            $table->string('destination', 64)->nullable(); // Room channel or targeted
            $table->json('payload')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_telemetry_logs');
        Schema::dropIfExists('room_players');
        Schema::dropIfExists('rooms');
    }
};
