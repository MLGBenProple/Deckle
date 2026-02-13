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
        Schema::create('daily_games', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('tournament_name')->nullable();
            $table->string('player_name')->nullable();
            $table->json('decklist');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_games');
    }
};
