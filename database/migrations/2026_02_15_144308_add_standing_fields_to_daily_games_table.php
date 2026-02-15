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
        Schema::table('daily_games', function (Blueprint $table) {
            $table->unsignedInteger('player_standing')->nullable()->after('player_name');
            $table->unsignedInteger('total_participants')->nullable()->after('player_standing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_games', function (Blueprint $table) {
            $table->dropColumn(['player_standing', 'total_participants']);
        });
    }
};
