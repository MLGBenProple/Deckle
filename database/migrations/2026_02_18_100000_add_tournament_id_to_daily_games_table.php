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
            $table->string('tournament_id')->nullable()->after('tournament_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_games', function (Blueprint $table) {
            $table->dropColumn('tournament_id');
        });
    }
};
