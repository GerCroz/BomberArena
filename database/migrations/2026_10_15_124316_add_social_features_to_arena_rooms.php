<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('name', 60)->nullable();
            $table->boolean('is_private')->default(true);
            $table->json('rematch_request')->nullable();
            $table->unsignedInteger('rematch_count')->default(0);
            $table->index(['is_private', 'status']);
        });
        Schema::table('room_players', function (Blueprint $table): void {
            $table->string('character_id', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('room_players', function (Blueprint $table): void {
            $table->dropColumn('character_id');
        });
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropIndex(['is_private', 'status']);
            $table->dropColumn(['name', 'is_private', 'rematch_request', 'rematch_count']);
        });
    }
};
